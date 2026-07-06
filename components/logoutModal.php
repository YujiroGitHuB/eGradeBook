<!-- ===== LOGOUT CONFIRMATION MODAL ===== -->
<div class="modal-backdrop" id="logoutModalBackdrop" onclick="if(event.target===this)closeLogoutModal()">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle" style="max-width:380px;text-align:center;">
        <div style="width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;background:rgba(239,68,68,.12);">
            <i class="bi bi-box-arrow-right" style="font-size:1.4rem;color:var(--danger);"></i>
        </div>
        <h3 id="logoutModalTitle">Log out?</h3>
        <p>Are you sure you want to log out of the eGradeBook? You'll need to sign in again to continue.</p>
        <div class="modal-actions" style="justify-content:center;">
            <button type="button" class="btn btn-ghost btn-sm" onclick="closeLogoutModal()">Cancel</button>
            <a href="#" id="logoutConfirmBtn" class="btn btn-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Log out</a>
        </div>
    </div>
</div>

<script>
    (function() {
        if (window.__logoutModalInit) return; // avoid double-init
        window.__logoutModalInit = true;

        window.confirmLogout = function(url) {
            var btn = document.getElementById('logoutConfirmBtn');
            if (btn) btn.setAttribute('href', url || '#');
            document.getElementById('logoutModalBackdrop').classList.add('show');
            document.body.style.overflow = 'hidden';
        };

        window.closeLogoutModal = function() {
            document.getElementById('logoutModalBackdrop').classList.remove('show');
            document.body.style.overflow = '';
        };

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') window.closeLogoutModal();
        });
    })();
</script>