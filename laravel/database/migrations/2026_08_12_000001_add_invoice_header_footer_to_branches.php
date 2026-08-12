<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add invoice header/footer image support to branches.
 *
 * Each branch can have its own custom invoice header and footer images
 * that are rendered on printed invoices. The images are stored as
 * file paths (relative to storage/app/public/).
 *
 * Recommended sizes (A4 invoice at 96 DPI):
 *   Header: 750 x 200 px (full width, ~5 cm height)
 *   Footer: 750 x 150 px (full width, ~4 cm height)
 *
 * Also adds invoice_header_text and invoice_footer_text for custom
 * HTML/text content below/above the images (e.g. terms, contact info).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('invoice_header_image', 500)->nullable()->after('email')
                ->comment('Path to branch invoice header image (recommended: 750x200px)');
            $table->string('invoice_footer_image', 500)->nullable()->after('invoice_header_image')
                ->comment('Path to branch invoice footer image (recommended: 750x150px)');
            $table->text('invoice_header_text')->nullable()->after('invoice_footer_image')
                ->comment('Custom HTML/text for invoice header area (below image)');
            $table->text('invoice_footer_text')->nullable()->after('invoice_header_text')
                ->comment('Custom HTML/text for invoice footer area (above image)');
            $table->string('invoice_watermark_text', 200)->nullable()->after('invoice_footer_text')
                ->comment('Watermark text for invoice (e.g. company name)');
            $table->string('invoice_signatory_name', 200)->nullable()->after('invoice_watermark_text')
                ->comment('Authorized signatory name for this branch');
            $table->string('invoice_signatory_title', 200)->nullable()->after('invoice_signatory_name')
                ->comment('Authorized signatory title/designation');
            $table->text('invoice_terms')->nullable()->after('invoice_signatory_title')
                ->comment('Terms & conditions text for invoice footer (Bengali/English)');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_header_image',
                'invoice_footer_image',
                'invoice_header_text',
                'invoice_footer_text',
                'invoice_watermark_text',
                'invoice_signatory_name',
                'invoice_signatory_title',
                'invoice_terms',
            ]);
        });
    }
};
