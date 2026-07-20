<?php
require_once __DIR__ . '/../../../core/Auth.php';

$branchName = $_SESSION['branch_name'] ?? 'No Branch';
$userName   = $_SESSION['employee_name'] ?? 'User';
$role       = $_SESSION['role'] ?? '';
?>

<nav class="navbar navbar-expand-lg navbar-dark shadow sticky-top " style="background:#f7f7f7;" >
    <div class="container-fluid">


        <!-- Left: Brand -->
        <!-- Center: Branch Name -->
        <div class="mx-auto  fw-bold d-none d-lg-block" style="color:#61bc91;">
            <i class="fas fa-building me-2 "></i>
            Branch: <?= htmlspecialchars($branchName) ?>
        </div>

        <!-- Right -->
        <div class="d-flex align-items-center gap-2">

        <button class="btn btn-outline-dark me-2 d-lg-none" onclick="toggleSidebar()">
    <i class="fas fa-bars"></i>
</button>

            <!-- Screenshot Button -->
            <button onclick="takeScreenshot()" class="btn btn-light btn-outline-dark btn-sm">
                <i class="fas fa-camera"></i> Screenshot
            </button>

            <!-- User Dropdown -->
            <div class="dropdown">
                <a href="#" class="btn btn-outline-dark btn-sm dropdown-toggle d-flex align-items-center"
                   data-bs-toggle="dropdown">
                    <i class="fas fa-user-circle me-2"></i>
                    <?= htmlspecialchars($userName) ?>
                </a>

                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li class="dropdown-item-text">
                        <strong><?= htmlspecialchars($userName) ?></strong><br>
                        <small class="text-muted"><?= htmlspecialchars($role) ?></small>
                    </li>

                    <li><hr class="dropdown-divider"></li>

                    <li>
                        <a class="dropdown-item" href="<?= BASE_URL ?>user/change_password">
                            <i class="fas fa-key me-2"></i> Change Password
                        </a>
                    </li>

                    <!-- Phase 0: Two-Factor Authentication link removed (2FA disabled). -->

                    <li>
                        <a class="dropdown-item" href="<?= BASE_URL ?>settings">
                            <i class="fas fa-cog me-2"></i> Settings
                        </a>
                    </li>

                    <li><hr class="dropdown-divider"></li>

                    <li>
                        <form method="POST" action="<?= BASE_URL ?>auth/logout" class="m-0">
                            <input type="hidden" name="csrf_token"
                                   value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
                            <button type="submit"
                                    class="dropdown-item text-danger border-0 bg-transparent w-100 text-start">
                                <i class="fas fa-sign-out-alt me-2"></i> Logout
                            </button>
                        </form>
                    </li>
                </ul>
            </div>

        </div>
    </div>
</nav>
<!-- Desktop Collapse Button -->
<button class="btn btn-outline-dark me-2 d-none d-lg-inline" onclick="toggleMiniSidebar()" title="Toggle Sidebar">
    <i class="fas fa-bars"></i>
</button>
<!-- html2canvas CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<script>
function takeScreenshot() {
    html2canvas(document.body).then(canvas => {
        const imgData = canvas.toDataURL();

        const win = window.open();
        win.document.write(`
        <html>
        <head>
            <title>Annotate Screenshot</title>
            <style>
                body { margin:0; background:#111; }
                #wrapper { position:relative; display:inline-block; }
                img { display:block; max-width:100%; }
                canvas { position:absolute; top:0; left:0; cursor:crosshair; }

                .toolbar {
                    position:fixed;
                    top:10px;
                    left:50%;
                    transform:translateX(-50%);
                    background:#fff;
                    padding:10px;
                    border-radius:10px;
                    box-shadow:0 2px 10px rgba(0,0,0,0.3);
                    z-index:999;
                }

                button, select {
                    margin:3px;
                    padding:5px 8px;
                }
            </style>
        </head>
        <body>

        <div class="toolbar">
            <select id="tool">
                <option value="draw">Draw</option>
                <option value="rect">Rectangle</option>
                <option value="arrow">Arrow</option>
                <option value="text">Text</option>
                <option value="blur">Blur</option>
            </select>

            <button onclick="setColor('red')">Red</button>
            <button onclick="setColor('blue')">Blue</button>
            <button onclick="setColor('green')">Green</button>

            <button onclick="clearCanvas()">Clear</button>
            <button onclick="download()">Download</button>
        </div>

        <div id="wrapper">
            <img id="img" src="${imgData}">
            <canvas id="canvas"></canvas>
        </div>

        <script>
            const img = document.getElementById('img');
            const canvas = document.getElementById('canvas');
            const ctx = canvas.getContext('2d');

            let drawing = false;
            let startX, startY;
            let tool = 'draw';
            let color = 'red';

            document.getElementById('tool').onchange = (e) => tool = e.target.value;

            img.onload = () => {
                canvas.width = img.width;
                canvas.height = img.height;
            };

            canvas.addEventListener('mousedown', e => {
                drawing = true;
                startX = e.offsetX;
                startY = e.offsetY;

                if (tool === 'text') {
                    const text = prompt('Enter text:');
                    if (text) {
                        ctx.fillStyle = color;
                        ctx.font = '16px Arial';
                        ctx.fillText(text, startX, startY);
                    }
                    drawing = false;
                }
            });

            canvas.addEventListener('mouseup', e => {
                if (!drawing) return;
                drawing = false;

                const endX = e.offsetX;
                const endY = e.offsetY;

                if (tool === 'rect') {
                    ctx.strokeStyle = color;
                    ctx.lineWidth = 2;
                    ctx.strokeRect(startX, startY, endX - startX, endY - startY);
                }

                if (tool === 'arrow') {
                    drawArrow(startX, startY, endX, endY);
                }

                if (tool === 'blur') {
                    blurArea(startX, startY, endX - startX, endY - startY);
                }
            });

            canvas.addEventListener('mousemove', e => {
                if (!drawing || tool !== 'draw') return;

                ctx.lineWidth = 3;
                ctx.lineCap = 'round';
                ctx.strokeStyle = color;

                ctx.lineTo(e.offsetX, e.offsetY);
                ctx.stroke();
                ctx.beginPath();
                ctx.moveTo(e.offsetX, e.offsetY);
            });

            function setColor(c) { color = c; }

            function clearCanvas() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
            }

            function drawArrow(x1, y1, x2, y2) {
                const headlen = 10;
                const dx = x2 - x1;
                const dy = y2 - y1;
                const angle = Math.atan2(dy, dx);

                ctx.strokeStyle = color;
                ctx.lineWidth = 2;

                ctx.beginPath();
                ctx.moveTo(x1, y1);
                ctx.lineTo(x2, y2);
                ctx.stroke();

                ctx.beginPath();
                ctx.moveTo(x2, y2);
                ctx.lineTo(x2 - headlen * Math.cos(angle - Math.PI / 6),
                           y2 - headlen * Math.sin(angle - Math.PI / 6));
                ctx.lineTo(x2 - headlen * Math.cos(angle + Math.PI / 6),
                           y2 - headlen * Math.sin(angle + Math.PI / 6));
                ctx.lineTo(x2, y2);
                ctx.fillStyle = color;
                ctx.fill();
            }

            function blurArea(x, y, w, h) {
                const imageData = ctx.getImageData(x, y, w, h);
                for (let i = 0; i < imageData.data.length; i += 4) {
                    imageData.data[i] = imageData.data[i] * 0.5;
                    imageData.data[i+1] = imageData.data[i+1] * 0.5;
                    imageData.data[i+2] = imageData.data[i+2] * 0.5;
                }
                ctx.putImageData(imageData, x, y);
            }

            function download() {
                const merged = document.createElement('canvas');
                const mctx = merged.getContext('2d');

                merged.width = canvas.width;
                merged.height = canvas.height;

                mctx.drawImage(img, 0, 0);
                mctx.drawImage(canvas, 0, 0);

                const link = document.createElement('a');
                link.download = 'annotated.png';
                link.href = merged.toDataURL();
                link.click();
            }
        <\/script>

        </body>
        </html>
        `);
    });
}
</script>

<style>
.navbar {
    background: linear-gradient(90deg, #0d6efd, #0b5ed7);
}

.navbar-brand {
    font-size: 1.2rem;
    letter-spacing: 0.5px;
}

.navbar .btn {
    border-radius: 6px;
}

.dropdown-menu {
    min-width: 220px;
    border-radius: 8px;
}
</style>