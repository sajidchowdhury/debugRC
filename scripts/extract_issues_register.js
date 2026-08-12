#!/usr/bin/env node
/**
 * extract_issues_register.js
 * --------------------------
 * Recursively scans all .md files under AI_CONTEXT/ and extracts every gap marker (G1..G99),
 * classifies its severity, captures inline code citations, and emits:
 *   1. A JSON dump to stdout (machine-parseable).
 *   2. A markdown register table to AI_CONTEXT/ISSUES_REGISTER.md (human-readable).
 *
 * Re-runnable (idempotent): rerun weekly to catch drift.
 *
 * Usage:
 *   node scripts/extract_issues_register.js > /tmp/issues_raw.json
 *
 * Patterns recognised:
 *   - Numbered gap IDs:           G1, G2, ... G99        (\bG(\d{1,2})\b)
 *   - Severity tags (bold):       **CRITICAL|HIGH|MEDIUM|LOW|MAJOR|MINOR**
 *   - Inline severity words:      CRITICAL|HIGH|MEDIUM|LOW|MAJOR|MINOR (case-insensitive)
 *   - Gap section headers:        ^#+ (Gaps?|Gap catalogue|Open Questions|Known Issues|Limitations|TODO|FIXME|Future improvements (gap catalogue))
 *   - Fix-section EXCLUSION:      titles containing the word "gap" are always treated as gap sections,
 *                                  never fix sections (protects combined titles like "Future improvements (gap catalogue)")
 *   - Gap definition headers:     ^#+ G\d
 *   - Gap table rows:             | **G\d** | SEVERITY | evidence | impact | fix |
 *   - Gap list items:             - **G\d ...** or N. **G\d ...**
 *   - Inline gap definitions:     **Gap G# (SEVERITY):** or **G# (SEVERITY)**
 *   - Code citations:             (laravel/)?(app|database|config|routes|tests)/...\.(php|sql):\d+
 */

const fs = require('fs');
const path = require('path');

const SCRIPT_DIR  = __dirname;
const PROJECT_ROOT = path.resolve(SCRIPT_DIR, '..');
const AI_CONTEXT   = path.join(PROJECT_ROOT, 'AI_CONTEXT');
const OUTPUT_MD    = path.join(AI_CONTEXT, 'ISSUES_REGISTER.md');

// ---------- Regex patterns ----------

// Capture G# ids (1 or 2 digits). \b prevents partial matches inside larger tokens.
const G_ID_RE = /\bG(\d{1,2})\b/g;

// Tagged severity: **CRITICAL**, **HIGH**, **MEDIUM**, **LOW**, **MAJOR**, **MINOR**.
const SEVERITY_TAG_RE = /\*\*(CRITICAL|HIGH|MEDIUM|LOW|MAJOR|MINOR)\*\*/gi;

// Inline severity word (used in `### G1 — CRITICAL — ...` headers, `MAJOR (G10)`, etc.).
const SEVERITY_INLINE_RE = /\b(CRITICAL|HIGH|MEDIUM|LOW|MAJOR|MINOR)\b/gi;

// Code citations: (laravel/)?(app|database|config|routes|tests)/path/to/File.php:LINE  (also .sql)
const CODE_CITATION_RE =
  /(?:laravel\/)?(app|database|config|routes|tests)\/[A-Za-z0-9_./-]+\.(?:php|sql):[0-9]+(?:[-,][0-9]+)*/g;

// Gap section headers (the containing section). Allows optional numeric prefix like "## 11. Gaps".
// Also accepts "## 13. Future improvements (gap catalogue)" — the api/* files use this combined
// title for their canonical gap catalogue (the G# IDs inside ARE definitions, not remediation).
const GAP_SECTION_RE =
  /^#+\s*(?:[\d.]+\s+)?(?:Gaps?(?:\s+catalogue)?|Open\s+Questions|Known\s+Issues|Limitations|TODO|FIXME|Future\s+improvements?\s*\(gap\s+catalogue\))\b/i;

// Fix/recommendation section headers — lines inside these are NOT gap definitions
// (they are recommended fixes that reference gap IDs by way of remediation guidance).
// IMPORTANT: a title containing the word "gap" anywhere is treated as a GAP section, not a
// fix section — this protects "Future improvements (gap catalogue)" and similar combined titles.
const FIX_SECTION_RE =
  /^#+\s*(?:[\d.]+\s+)?(?:Future\s+improvements?|Recommended\s+fixes?|Remediations?|Proposed\s+fixes?|Fix\s+recommendations?|Action\s+items?|Next\s+steps?|Remediation\s+plan|Review\s+checklist|Accountant\s+review\s+checklist)\b(?!.*\bgap\b)/i;

// Gap-definition patterns (each defines a gap, not just references one).
const GAP_HEADER_RE      = /^#+\s+G(\d{1,2})\b/;                     // ### G1 — CRITICAL — title
const GAP_TABLE_ROW_RE   = /^\|\s*\*{0,2}\s*G(\d{1,2})\b/;           // | **G1** | SEV | ...
const GAP_LIST_ITEM_RE   = /^(?:\d+\.\s+|-\s+)\*{0,2}\s*G(\d{1,2})\b/; // 1. **G2 (CRITICAL)** — ...
const GAP_BLOCKQUOTE_RE  = /^>\s+\*{0,2}\s*G(\d{1,2})\b/;            // > **G1:** description
const GAP_INLINE_DEF_RE  = /\*\*(?:Gap\s+)?G(\d{1,2})\s*(?:\(|—|:|--)/i; // **G15 — or **G1 (CRITICAL) or **Gap G1 (CRITICAL):

// Noise patterns — lines matching these are skipped entirely (no G# extraction).
const NOISE_PATTERNS = [
  /^\s*\[\s*\]/,                          // checklist items
  /\bG\d{1,2}\s*[-–]\s*G\d{1,2}/,         // G1-G8 ranges
  /\bG\d{1,2}\s*,\s*G\d{1,2}\s*,\s*G\d{1,2}/, // 3+ comma-separated G#s
  /\bG\d{1,2}\s*\/\s*G\d{1,2}\s*\/\s*G\d{1,2}/, // 3+ slash-separated G#s
  /\bG\d{1,2}\s*\+\s*G\d{1,2}\s*\+\s*G\d{1,2}/, // 3+ plus-separated G#s
  /\(reaffirms?\s+G\d/i,                  // (reaffirms G1)
  /\(cross-?ref(?:erence)?\s+`?[^`]*G\d/i, // (cross-ref `...` G1)
  /\bsee\s+Gap\s+G\d/i,                   // see Gap G1
  /\bsee\s+G\d/i,                         // see G1
  /\bgap\s+G\d+:/i,                       // inline "gap G1:" reference
];

// Files in the changelog/ directory are meta-summaries, not source gap definitions.
// They are excluded from the main register but still tracked in the cross-reference matrix.
const META_SUMMARY_DIRS = ['changelog'];

// ---------- Helpers ----------

function walk(dir) {
  const out = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) out.push(...walk(full));
    else if (entry.isFile() && entry.name.endsWith('.md')) out.push(full);
  }
  return out;
}

function normalizeSeverity(s) {
  if (!s) return 'UNKNOWN';
  const u = s.toUpperCase();
  if (u === 'MAJOR')  return 'HIGH';
  if (u === 'MINOR')  return 'LOW';
  return u; // CRITICAL | HIGH | MEDIUM | LOW | WONTFIX
}

function severityRank(s) {
  return { CRITICAL: 0, HIGH: 1, MEDIUM: 2, LOW: 3, WONTFIX: 4, UNKNOWN: 5 }[s] ?? 6;
}

// ---------- Severity overrides (manual triage) ----------
// Reads the existing ISSUES_REGISTER.md (if present) and builds a map of
// (gid, sourceFile) -> severity for rows where severity is NOT UNKNOWN.
// These overrides are applied AFTER extraction, ONLY when the extracted
// severity is UNKNOWN. This preserves source-file severity tags (which
// take precedence) while allowing manual triage of UNKNOWN gaps.
//
// Background: the extractor derives severity from tags in source AI_CONTEXT
// files. Gaps without severity tags are classified UNKNOWN. The triage
// subagent (Task 25) manually assigns severities to UNKNOWN gaps by editing
// the Severity column of ISSUES_REGISTER.md. Without this override logic,
// re-running the extractor would overwrite those manual assignments with
// UNKNOWN again. The override map preserves them across re-extractions.
//
// See: AI_CONTEXT/TRIAGE_FINANCE_UNKNOWN.md for the triage methodology.
function readSeverityOverrides() {
  const overrides = new Map(); // key=`${gid}|${sourceFile}` -> severity
  if (!fs.existsSync(OUTPUT_MD)) return overrides;
  const content = fs.readFileSync(OUTPUT_MD, 'utf8');
  const lines = content.split('\n');
  for (const line of lines) {
    // Match register rows: | G-XXX | <gid> | <severity> | <sector> | <source>:<line> | ...
    const m = line.match(/^\| G-\d+ \| ([^|]+) \| ([^|]+) \| [^|]+ \| ([^|]+?):\d+ \|/);
    if (!m) continue;
    const gid = m[1].trim();
    const severity = m[2].trim();
    const sourceWithLine = m[3].trim();
    if (severity === 'UNKNOWN' || severity === '—' || severity === '') continue;
    // Override key uses (gid, sourceFile) — same as the extractor's dedup key.
    const key = `${gid}|${sourceWithLine}`;
    overrides.set(key, severity);
  }
  return overrides;
}

function applySeverityOverrides(entries, overrides) {
  let applied = 0;
  for (const e of entries) {
    if (e.severity !== 'UNKNOWN') continue; // Only override UNKNOWN entries.
    const key = `${e.gid}|${e.sourceFile}`;
    if (overrides.has(key)) {
      const newSev = overrides.get(key);
      // Only apply if the override is a recognized severity (not UNKNOWN).
      if (newSev && newSev !== 'UNKNOWN') {
        e.severity = newSev;
        e.severityOverride = true; // Flag for traceability.
        applied++;
      }
    }
  }
  return applied;
}

function extractSeverity(line, ctxLines) {
  // First try tagged severity on the line itself (highest confidence).
  const taggedOnLine = [...line.matchAll(SEVERITY_TAG_RE)].map(m => m[1]);
  if (taggedOnLine.length > 0) {
    return normalizeSeverity(taggedOnLine[0]);
  }
  // Then look at inline severity word on the current line.
  const inlineOnLine = line.match(SEVERITY_INLINE_RE);
  if (inlineOnLine) return normalizeSeverity(inlineOnLine[0]);
  // Finally, look at tagged severity OR inline severity word within ±3 lines of context.
  for (const ctx of ctxLines) {
    const tagged = [...ctx.matchAll(SEVERITY_TAG_RE)].map(m => m[1]);
    if (tagged.length > 0) return normalizeSeverity(tagged[0]);
    const inline = ctx.match(SEVERITY_INLINE_RE);
    if (inline) return normalizeSeverity(inline[0]);
  }
  return null;
}

function extractCodeCitations(line, ctxLines) {
  const all = [line, ...ctxLines].join('\n');
  const matches = [...all.matchAll(CODE_CITATION_RE)].map(m => m[0]);
  return [...new Set(matches)];
}

function isDefinition(line) {
  return (
    GAP_HEADER_RE.test(line)      ||
    GAP_TABLE_ROW_RE.test(line)   ||
    GAP_LIST_ITEM_RE.test(line)   ||
    GAP_BLOCKQUOTE_RE.test(line)  ||
    GAP_INLINE_DEF_RE.test(line)
  );
}

function isNoiseLine(line) {
  return NOISE_PATTERNS.some(re => re.test(line));
}

function isMetaSummaryFile(rel) {
  const parts = rel.split(path.sep);
  return parts.length > 1 && META_SUMMARY_DIRS.includes(parts[0]);
}

// The output file itself must never be scanned (would create self-reference loops).
function isSelfOutputFile(rel) {
  return rel === 'ISSUES_REGISTER.md';
}

function cleanSentence(line) {
  let s = line.trim();
  // Strip leading table-row pipes for readability.
  if (s.startsWith('|')) s = s.replace(/^\|\s*/, '');
  // Strip leading list markers.
  s = s.replace(/^(?:\d+\.\s+|-\s+|>\s+)/, '');
  // Collapse runs of whitespace.
  s = s.replace(/\s+/g, ' ').trim();
  // Cap length.
  if (s.length > 200) s = s.slice(0, 200).replace(/\s+\S*$/, '') + '…';
  return s;
}

function sectorFromPath(rel) {
  // rel = "security/rbac-roles-permissions.md" or "README.md"
  const parts = rel.split(path.sep);
  if (parts.length === 1) return 'cross-cutting';
  return parts[0];
}

// ---------- Resolved-marker detection ----------
// A gap is considered RESOLVED when a line matching the resolved-marker regex
// appears below the gap definition. The marker format (appended by remediation
// commits):
//   > ✅ RESOLVED in commit <hash> — <one-line description>
// We look backwards from each marker, line by line, until we find the first
// line containing a G# mention. That G# is the one being resolved. This avoids
// false positives from nearby (but unrelated) gap definitions in the same list
// or table. The commit hash is captured for display in the register's
// "Resolved" column.
const RESOLVED_MARKER_RE = /^\s*>\s*✅\s*RESOLVED\s+in\s+commit\s+([0-9a-f]{7,40})\b/i;

function extractResolvedGids(lines) {
  const resolved = new Map(); // gid -> commitHash
  for (let i = 0; i < lines.length; i++) {
    const m = lines[i].match(RESOLVED_MARKER_RE);
    if (!m) continue;
    const commitHash = m[1];
    // Walk backwards from the marker until we find a line with a G# mention.
    // Stop after 15 lines (safety limit — gap descriptions are rarely longer).
    for (let j = i - 1; j >= Math.max(0, i - 15); j--) {
      const ids = [...lines[j].matchAll(G_ID_RE)].map(m => 'G' + m[1]);
      if (ids.length > 0) {
        for (const id of ids) {
          if (!resolved.has(id)) resolved.set(id, commitHash);
        }
        break; // stop at the first line containing a G# mention
      }
    }
  }
  return resolved;
}

// ---------- Main extraction ----------

function extract() {
  const files = walk(AI_CONTEXT).sort();
  const rawEntries = []; // one per (file, line, G#)
  const allMentions = []; // for cross-ref matrix: every (gid, file) pair mentioned anywhere

  for (const file of files) {
    const rel = path.relative(AI_CONTEXT, file);
    if (isSelfOutputFile(rel)) continue;             // never scan own output
    const metaSummary = isMetaSummaryFile(rel);
    const lines = fs.readFileSync(file, 'utf8').split('\n');
    let currentGapSection = null;
    let inFixSection = false;

    // Build the (gid -> commitHash) map for any gap marked resolved in this file.
    const resolvedGidsInFile = extractResolvedGids(lines);

    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];

      // Track the current gap-containing section header.
      if (GAP_SECTION_RE.test(line)) {
        currentGapSection = line.replace(/^#+\s*/, '').trim();
        inFixSection = false;       // gap section overrides any prior fix section
      } else if (FIX_SECTION_RE.test(line)) {
        inFixSection = true;
        // do NOT clear currentGapSection — it stays as the last seen gap section for context
      } else if (/^#+\s/.test(line)) {
        // A new non-gap, non-fix section: clear both states.
        if (!GAP_SECTION_RE.test(line) && !FIX_SECTION_RE.test(line)) {
          // Only clear inFixSection if this new section isn't a sub-section of the current fix section.
          // Heuristic: same heading level or higher (fewer #) means a new top-level section.
          const curHashCount = (line.match(/^#+/) || [''])[0].length;
          const fixHashCount = currentGapSection ? 2 : 2; // simplification
          if (curHashCount <= 2) inFixSection = false;
        }
      }

      // Find all G# ids on this line.
      const ids = [...line.matchAll(G_ID_RE)].map(m => parseInt(m[1], 10));
      if (ids.length === 0) continue;

      // Track ALL mentions (for the cross-reference matrix).
      for (const id of [...new Set(ids)]) {
        allMentions.push({ gid: 'G' + id, sourceFile: rel, sector: sectorFromPath(rel) });
      }

      // Skip noise lines entirely (no definition extraction).
      if (isNoiseLine(line)) continue;

      // Skip meta-summary files for the main register (their mentions are still tracked above).
      if (metaSummary) continue;

      // Skip lines inside fix/recommendation sections (they are remediation guidance,
      // not gap definitions).
      if (inFixSection) continue;

      // Only treat as a candidate definition if the line matches a definition pattern.
      const definition = isDefinition(line);
      if (!definition) continue;

      // Build ±3-line context window (excluding current line).
      const ctx = [];
      for (let j = Math.max(0, i - 3); j <= Math.min(lines.length - 1, i + 3); j++) {
        if (j !== i) ctx.push(lines[j]);
      }

      const severity = extractSeverity(line, ctx) || 'UNKNOWN';
      const codeRefs = extractCodeCitations(line, ctx);
      const sentence = cleanSentence(line);

      for (const id of [...new Set(ids)]) {
        const gidKey = 'G' + id;
        rawEntries.push({
          gid:        gidKey,
          sourceFile: rel,
          sector:     sectorFromPath(rel),
          line:       i + 1,
          severity:   severity,
          codeRefs:   codeRefs,
          definition: definition,
          section:    currentGapSection,
          sentence:   sentence,
          resolved:      resolvedGidsInFile.has(gidKey),
          resolvedCommit: resolvedGidsInFile.get(gidKey) || null,
        });
      }
    }
  }

  // ---------- Deduplicate by (gid + sourceFile) ----------
  // For each (file, gid) pair, keep the best entry:
  //   1. Prefer rows with severity != UNKNOWN.
  //   2. Among those, prefer the highest severity.
  //   3. Among ties, prefer the earliest line.
  // Also count how many lines in that file reference this gid (for cross-ref matrix).

  const byKey = new Map(); // key=`${gid}|${sourceFile}` -> { best, refLines: [], allSeverities }

  for (const e of rawEntries) {
    const key = `${e.gid}|${e.sourceFile}`;
    if (!byKey.has(key)) {
      byKey.set(key, { best: e, refLines: [e.line], severities: new Set([e.severity]) });
      continue;
    }
    const slot = byKey.get(key);
    slot.refLines.push(e.line);
    slot.severities.add(e.severity);
    const cur = slot.best;
    // Prefer non-resolved entries over resolved ones (a file may have
    // multiple gap definitions for the same G#; if ANY is marked resolved,
    // the (gid, file) pair is considered resolved — but if another mention
    // is NOT under a resolved marker, that one wins as the canonical entry).
    const better =
      (e.resolved !== cur.resolved) ? (!e.resolved && cur.resolved) :
      (severityRank(e.severity) < severityRank(cur.severity)) ||
      (e.severity === cur.severity && e.line < cur.line);
    if (better) slot.best = e;
  }

  const entries = [...byKey.values()].map(slot => ({
    ...slot.best,
    refCount:    slot.refLines.length,
    refLines:    [...new Set(slot.refLines)].sort((a, b) => a - b),
    severities:  [...slot.severities],
  }));

  // Sort: severity rank, then sector, then gid numeric, then file.
  entries.sort((a, b) => {
    if (severityRank(a.severity) !== severityRank(b.severity))
      return severityRank(a.severity) - severityRank(b.severity);
    if (a.sector !== b.sector) return a.sector.localeCompare(b.sector);
    const ga = parseInt(a.gid.slice(1), 10);
    const gb = parseInt(b.gid.slice(1), 10);
    if (ga !== gb) return ga - gb;
    return a.sourceFile.localeCompare(b.sourceFile);
  });

  // Build cross-reference matrix: gid -> Set of sourceFile (from ALL mentions, including meta-summary).
  const crossRef = new Map(); // gid -> Map<sourceFile, count>
  const gidSector = new Map(); // gid -> Set of sector
  for (const m of allMentions) {
    if (!crossRef.has(m.gid)) crossRef.set(m.gid, new Map());
    const fileMap = crossRef.get(m.gid);
    fileMap.set(m.sourceFile, (fileMap.get(m.sourceFile) || 0) + 1);
    if (!gidSector.has(m.gid)) gidSector.set(m.gid, new Set());
    gidSector.get(m.gid).add(m.sector);
  }

  return { files, entries, crossRef, gidSector };
}

// ---------- Markdown emit ----------

function emitMarkdown({ files, entries, crossRef, gidSector }) {
  // Use Asia/Dhaka timezone (UTC+6) for the "Last reviewed" stamp per the ISSUES_REGISTER spec.
  const today = new Date().toLocaleDateString('en-CA', {
    timeZone: 'Asia/Dhaka',
    year: 'numeric', month: '2-digit', day: '2-digit',
  }); // YYYY-MM-DD

  // Counts — resolved entries are kept in the register (for traceability)
  // but EXCLUDED from the severity + sector counts so the "at a glance"
  // table reflects only open gaps.
  const sevCounts = { CRITICAL: 0, HIGH: 0, MEDIUM: 0, LOW: 0, WONTFIX: 0, UNKNOWN: 0 };
  const sectorCounts = {};
  let resolvedCount = 0;
  for (const e of entries) {
    if (e.resolved) { resolvedCount++; continue; }
    sevCounts[e.severity] = (sevCounts[e.severity] || 0) + 1;
    sectorCounts[e.sector] = (sectorCounts[e.sector] || 0) + 1;
  }

  // Cross-ref matrix: gids cited in 2+ files (including meta-summary files).
  const multiFileGids = [...crossRef.entries()]
    .filter(([_, fileMap]) => fileMap.size >= 2)
    .sort((a, b) => b[1].size - a[1].size)
    .map(([gid, fileMap]) => ({
      gid,
      files: [...fileMap.keys()].sort(),
      sectors: [...gidSector.get(gid)].sort(),
      fileCount: fileMap.size,
    }));

  // ---------- Build the markdown ----------
  const lines = [];

  lines.push('---');
  lines.push(`Title: Issues Register`);
  lines.push(`Module: Cross-cutting`);
  lines.push(`Audience: Engineering + Product`);
  lines.push(`Status: Living document`);
  lines.push(`Last reviewed: ${today}`);
  lines.push(`Source of truth: This file consolidates gaps documented across all AI_CONTEXT/*.md files`);
  lines.push('---');
  lines.push('');
  lines.push('# Issues Register — Consolidated Gap Catalogue');
  lines.push('');
  lines.push('> **Purpose:** Single source of truth for every gap, issue, technical debt, and open');
  lines.push('> question discovered during the AI_CONTEXT documentation effort (Phases 0–21).');
  lines.push(`> Extracted from ${files.length} source files. Re-extractable via`);
  lines.push('> `node scripts/extract_issues_register.js`.');
  lines.push('');
  lines.push('## Summary at a glance');
  lines.push('');
  lines.push('| Severity | Count | Blocks cutover? |');
  lines.push('|---|---|---|');
  lines.push(`| CRITICAL | ${sevCounts.CRITICAL || 0} | Yes — all of them |`);
  lines.push(`| HIGH | ${sevCounts.HIGH || 0} | Most |`);
  lines.push(`| MEDIUM | ${sevCounts.MEDIUM || 0} | Some |`);
  lines.push(`| LOW | ${sevCounts.LOW || 0} | No |`);
  if (sevCounts.WONTFIX) lines.push(`| WONTFIX | ${sevCounts.WONTFIX} | False positive / not actionable |`);
  if (sevCounts.UNKNOWN) lines.push(`| UNKNOWN | ${sevCounts.UNKNOWN} | Triage needed |`);
  lines.push(`| **TOTAL open** | **${entries.length - resolvedCount}** | |`);
  if (resolvedCount > 0) lines.push(`| _of which resolved_ | ${resolvedCount} | (kept for traceability, excluded from counts above) |`);
  lines.push('');
  lines.push('### By sector');
  lines.push('');
  lines.push('| Sector | Open issues |');
  lines.push('|---|---|');
  for (const sec of Object.keys(sectorCounts).sort()) {
    lines.push(`| ${sec} | ${sectorCounts[sec]} |`);
  }
  lines.push('');
  lines.push('## How to use this register');
  lines.push('');
  lines.push('1. **Sort by Severity × ROADMAP horizon.** Never start a HIGH while a CRITICAL is open.');
  lines.push('2. **Cluster by shared files.** Gaps citing the same source file should be fixed together.');
  lines.push('3. **Update dual-write:** when you resolve a gap, mark it ✅ here AND in the source AI_CONTEXT file.');
  lines.push('4. **Re-extract weekly:** rerun the script to catch drift.');
  lines.push('');
  lines.push('## Legend');
  lines.push('');
  lines.push('- **ID** — sequential G-001..G-NNN (this register\'s own numbering)');
  lines.push('- **Orig ID** — the G# from the source file (e.g. G1, G7)');
  lines.push('- **Severity** — CRITICAL / HIGH / MEDIUM / LOW / WONTFIX (MAJOR→HIGH, MINOR→LOW, UNKNOWN=untriaged). WONTFIX = false positive / not actionable. Manually-triaged severities for UNKNOWN gaps are preserved across re-extractions via `readSeverityOverrides()`.');
  lines.push('- **Sector** — which AI_CONTEXT subfolder');
  lines.push('- **Source** — `path/to/file.md:line`');
  lines.push('- **Code ref** — `laravel/path:line` if cited');
  lines.push('- **Summary** — one-line description');
  lines.push('- **Blocks** — what is blocked (cutover / RLS audit / API phase / etc.)');
  lines.push('- **Horizon** — H1/H2/H3/H4 from ROADMAP.md (best judgement)');
  lines.push('- **Status** — open / in-progress / resolved / wontfix');
  lines.push('- **Resolved** — commit hash that closed this gap (or `—` if open)');
  lines.push('');
  lines.push('## Register');
  lines.push('');
  lines.push('| ID | Orig | Severity | Sector | Source | Code ref | Summary | Blocks | Horizon | Status | Resolved |');
  lines.push('|----|------|----------|--------|--------|----------|---------|--------|----------|--------|----------|');

  entries.forEach((e, idx) => {
    const id = 'G-' + String(idx + 1).padStart(3, '0');
    const codeRef = (e.codeRefs && e.codeRefs.length > 0) ? e.codeRefs[0] : '—';
    let summary = e.sentence.replace(/\|/g, '\\|').replace(/\n/g, ' ');
    if (summary.length > 140) summary = summary.slice(0, 140) + '…';
    const source = `${e.sourceFile}:${e.line}`;
    const blocks = blocksFor(e);
    const horizon = horizonFor(e);
    const status = e.resolved ? 'resolved' : 'open';
    const resolvedCol = e.resolvedCommit ? '`' + e.resolvedCommit + '`' : '—';
    lines.push(
      `| ${id} | ${e.gid} | ${e.severity} | ${e.sector} | ${source} | ${codeRef} | ${summary} | ${blocks} | ${horizon} | ${status} | ${resolvedCol} |`
    );
  });

  lines.push('');
  lines.push('## Cross-reference matrix — gaps cited in multiple files');
  lines.push('');
  lines.push('| Orig ID | Cited in (N files) | Files | Sectors touched |');
  lines.push('|---|---|---|---|');
  for (const m of multiFileGids) {
    const fileList = m.files.join(', ');
    const sectors  = m.sectors.join(', ');
    lines.push(`| ${m.gid} | ${m.fileCount} | ${fileList} | ${sectors} |`);
  }

  lines.push('');
  lines.push('## Top fix-clusters (recommended execution order)');
  lines.push('');
  const clusters = computeClusters(entries);
  clusters.forEach((c, i) => {
    lines.push(`${i + 1}. **${c.name}** — ${c.rows} rows. ${c.note}`);
  });

  lines.push('');
  lines.push(`*Auto-generated by \`scripts/extract_issues_register.js\`. Last extraction: ${today}.*`);
  lines.push('');

  return lines.join('\n');
}

// ---------- Heuristic: "Blocks" assignment ----------
function blocksFor(e) {
  const s = (e.sentence + ' ' + (e.codeRefs.join(' '))).toLowerCase();
  if (/rls|policy|branch.?isol|role:?|middleware|permission/.test(s))         return 'cutover, RLS audit';
  if (/audit.?log|fn_financial_audit_trigger|hash.?chain|tamper/.test(s))    return 'audit-trail phase';
  if (/routes\/api|api\/v1|api.?convent|apiconsumer/.test(s))                return 'API Phase 17';
  if (/migration|ddl|stale|schema|mismatch/.test(s))                        return 'DDL drift';
  if (/partition|parquet|duckdb|cold.?storage/.test(s))                     return 'partitioning ops';
  if (/notification|sse|listen?notify|redis|worker/.test(s))               return 'notifications phase';
  if (/journal|gl.?post|reversal|subledger/.test(s))                       return 'GL posting';
  if (/export|csv|bom/.test(s))                                            return 'reports phase';
  if (/test/.test(s))                                                      return 'test debt';
  return '—';
}

// ---------- Heuristic: ROADMAP horizon assignment ----------
function horizonFor(e) {
  const s = (e.sentence + ' ' + e.gid + ' ' + (e.codeRefs.join(' '))).toLowerCase();
  if (e.severity === 'CRITICAL') return 'H1';
  if (/rls|role:?middleware|cutover|fn_financial_audit_trigger/.test(s))  return 'H1';
  if (e.severity === 'HIGH') return 'H2';
  if (/test|coverage/.test(s)) return 'H3';
  if (e.severity === 'MEDIUM') return 'H2';
  if (e.severity === 'LOW')    return 'H4';
  return 'H3';
}

// ---------- Heuristic: cluster detection ----------
function computeClusters(entries) {
  const buckets = [];

  const push = (name, predicate, note) => {
    const rows = entries.filter(predicate).length;
    if (rows > 0) buckets.push({ name, rows, note });
  };

  push('Security/RLS cluster — missing role middleware + RLS gaps',
       e => /\b(rls|role:|middleware|policy|permission|branch.?isol)/i.test(e.sentence) ||
            e.codeRefs.some(r => /routes\/(web|api)\.php/.test(r)),
       'Fix once in routes/web.php + routes/api.php + middleware registration. Blocks cutover.');

  push('fn_financial_audit_trigger attachment cluster',
       e => /fn_financial_audit_trigger|hash.?chain|tamper/i.test(e.sentence),
       'Recurring cross-phase gap. Attach trigger to all sub-ledger tables. Closes ~10 rows.');

  push('Notification worker-forward cluster (G1/G2/G3 + dead events)',
       e => /notification|sse|listen?notify|worker|channel_event_map|double.?dispatch/i.test(e.sentence),
       'Remove CHANNEL_EVENT_MAP entries; dispatch directly from PHP. Closes notification-workflow G1-G3 + cascade.');

  push('CSV/export cluster — no role middleware, no BOM, no throttle, no audit row',
       e => e.sector === 'reports' && /\b(export|csv|bom|throttle|audit)/i.test(e.sentence),
       'Add role middleware to admin/reports group + standardise on CsvExporter. Closes ~10 reports rows.');

  push('Journal posting & reversal cluster (JournalPostingService::reverseJournalEntry)',
       e => /journal|reverseJournalEntry|JournalReversalService|reverseJournal|postJournal/i.test(e.sentence),
       'Standardise reversal path on JournalReversalService::reverseByJournalEntry. Closes ~6 rows.');

  push('DDL/migration drift cluster (stale migrations vs documented schema)',
       e => /\b(stale|ddl|mismatch|drift|migration)\b/i.test(e.sentence),
       'Reconcile database/sql/*.sql baseline with migrations. Closes ~8 rows.');

  push('Fiscal-year & period-close enforcement cluster',
       e => /fiscal.?year|period.?close|validatePeriod|back.?dated/i.test(e.sentence),
       'Wire fiscal-period consultation into all posting services. Closes ~5 rows.');

  push('FormRequest / validation cluster (inline $request->validate)',
       e => /formrequest|validate\(|inline.+validate/i.test(e.sentence),
       'Convert inline validate() to FormRequest classes. Closes ~7 rows.');

  push('Audit logger & SalesAuditLogger cluster',
       e => /audit.?log|salesauditlogger|userauditlogger/i.test(e.sentence),
       'Add saleUpdated()/missing methods. Closes ~4 rows.');

  push('Partitioning & Parquet export cluster (DuckDB + partition_exports)',
       e => /partition|parquet|duckdb|cold.?storage/i.test(e.sentence),
       'Install DuckDB + create partition_exports manifest. Closes ~3 rows.');

  push('ApiResource / API conventions cluster',
       e => /apiresource|api\/v1|api.?convent|pagination|envelope/i.test(e.sentence),
       'Standardise on ApiResource + JSON envelope. Closes ~6 rows.');

  push('Maker-checker / approval workflow cluster',
       e => /approval|maker.?checker|approved_by|is_parallel/i.test(e.sentence),
       'Add maker-checker to fixed-assets, budgets, manual-journals. Closes ~5 rows.');

  // Sort by row count descending, keep top 10.
  buckets.sort((a, b) => b.rows - a.rows);
  return buckets.slice(0, 10);
}

// ---------- Entry point ----------

function main() {
  const { files, entries, crossRef, gidSector } = extract();

  // Apply manual severity overrides (read from existing ISSUES_REGISTER.md).
  // This preserves manually-triaged severities for UNKNOWN gaps across
  // re-extractions. See readSeverityOverrides() for details.
  //
  // NOTE: Overrides are applied AFTER extraction but BEFORE emitMarkdown.
  // We do NOT re-sort after applying overrides — this preserves register ID
  // stability (G-XXX IDs are assigned by sorted position in emitMarkdown).
  // The 38 formerly-UNKNOWN rows stay at their original positions (the bottom
  // of the register, since UNKNOWN sorts last), now with overridden
  // severities. The summary counts in emitMarkdown reflect the overrides
  // (computed from the final severity field).
  const overrides = readSeverityOverrides();
  const appliedCount = applySeverityOverrides(entries, overrides);
  if (appliedCount > 0) {
    process.stderr.write(`[extract_issues_register] Applied ${appliedCount} manual severity overrides (from existing ISSUES_REGISTER.md).\n`);
  }

  const md = emitMarkdown({ files, entries, crossRef, gidSector });
  fs.writeFileSync(OUTPUT_MD, md, 'utf8');

  // JSON to stdout (summary + entries). Resolved entries remain in `entries`
  // for traceability but are excluded from the open-counts below.
  const openEntries = entries.filter(e => !e.resolved);
  const resolvedEntries = entries.filter(e => e.resolved);
  const summary = {
    generatedAt: new Date().toISOString(),
    sourceFiles: files.length,
    totalEntries: entries.length,
    openEntries: openEntries.length,
    resolvedEntries: resolvedEntries.length,
    bySeverity: openEntries.reduce((acc, e) => { acc[e.severity] = (acc[e.severity] || 0) + 1; return acc; }, {}),
    bySector: openEntries.reduce((acc, e) => { acc[e.sector] = (acc[e.sector] || 0) + 1; return acc; }, {}),
    crossRefSize: [...crossRef.values()].filter(m => m.size >= 2).length,
    entries,
  };
  process.stdout.write(JSON.stringify(summary, null, 2) + '\n');

  process.stderr.write(`\n[extract_issues_register] Wrote ${entries.length} entries from ${files.length} files → ${OUTPUT_MD}\n`);
}

main();
