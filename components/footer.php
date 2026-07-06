<!-- ===== FOOTER ===== -->
<footer class="site-footer">
    <div class="footer-inner">

        <!-- Brand col -->
        <div class="footer-brand">
            <a href="index.php" class="footer-logo">
                <img src="assets/images/logo.png" width="35px" height="35px" alt="eGradeBook">
                <span>eGradeBook</span>
            </a>
            <p class="footer-tagline">Standalone grading sheets, bridged to your student &amp; form data.</p>
        </div>

        <!-- Links col -->
        <div class="footer-links">
            <span class="footer-links-label">Quick Links</span>
            <ul>
                <li><a href="index.php"><i class="bi bi-table"></i> Grading Sheet</a></li>
                <li><a href="inc/logout.php" onclick="confirmLogout(this.href);return false;"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
            </ul>
        </div>

        <!-- Info col -->
        <div class="footer-links">
            <span class="footer-links-label">Support</span>
            <ul>
                <li><a href="#" onclick="openSupportModal('help');return false;"><i class="bi bi-question-circle"></i> Help Center</a></li>
                <li><a href="#" onclick="openSupportModal('privacy');return false;"><i class="bi bi-shield-check"></i> Privacy Policy</a></li>
                <li><a href="#" onclick="openSupportModal('terms');return false;"><i class="bi bi-file-text"></i> Terms of Use</a></li>
            </ul>
        </div>

    </div>

    <!-- Bottom bar -->
    <div class="footer-bottom">
        <span style="display:flex;align-items:center;gap:6px;">
            <i class="bi bi-c-circle" style="opacity:.5;"></i>
            <span><?php echo date('Y'); ?> <strong>eGradeBook</strong> — All Rights Reserved.</span>
            <span style="opacity:.35;">·</span>
            Developed by
            <a href="https://cncc.vercel.app" target="_blank"
                style="display:inline-flex;align-items:center;gap:5px;
                   background:linear-gradient(135deg,var(--accent),var(--accent2));
                   -webkit-background-clip:text;-webkit-text-fill-color:transparent;
                   background-clip:text;font-weight:600;text-decoration:none;
                   transition:opacity .2s;"
                onmouseover="this.style.opacity='.75'"
                onmouseout="this.style.opacity='1'">
                <i class="bi bi-person-circle"
                    style="-webkit-text-fill-color:var(--accent);color:var(--accent);font-size:.8rem;"></i>
                Charles Nixon Cayading
            </a>
        </span>
        <span class="footer-bottom-right">
            Made with <i class="bi bi-heart-fill" style="color:var(--accent);font-size:.75rem;"></i> for accurate grades
        </span>
    </div>

</footer>
<?php include __DIR__ . '/supportModal.php'; ?>
<?php include __DIR__ . '/logoutModal.php'; ?>
