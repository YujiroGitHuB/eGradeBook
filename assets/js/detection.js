/**
 * Universal In-App Browser Detector
 * Reusable across multiple pages
 * 
 * Usage:
 * 1. Load SweetAlert2 first: <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
 * 2. Load this script: <script src="browser-detector.js"></script>
 * 3. Initialize: <script>BrowserDetector.init();</script>
 */

const BrowserDetector = (function () {
    'use strict';

    // Private methods
    function detectInAppBrowser() {
        const ua = navigator.userAgent || navigator.vendor || window.opera;

        const isInAppBrowser = {
            messenger: /\bFB[\w_]+\/(Messenger|MESSENGER)/.test(ua) || /\bMessengerLite/.test(ua),
            facebook: /\bFB[\w_]+\//.test(ua) && !/\bMessenger/.test(ua),
            instagram: /Instagram/.test(ua),
            tiktok: /TikTok/.test(ua),
            twitter: /Twitter/.test(ua),
            linkedin: /LinkedInApp/.test(ua),
            line: /Line\//.test(ua),
            wechat: /MicroMessenger/.test(ua),
            viber: /Viber/.test(ua),
            whatsapp: /WhatsApp/.test(ua),
            snapchat: /Snapchat/.test(ua)
        };

        for (let browser in isInAppBrowser) {
            if (isInAppBrowser[browser]) {
                return {
                    isInApp: true,
                    browserName: browser.charAt(0).toUpperCase() + browser.slice(1)
                };
            }
        }

        return { isInApp: false, browserName: null };
    }

    function copyURLAndClose() {
        const url = window.location.href;

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(() => {
                showSuccessAlert();
            }).catch(() => {
                fallbackCopyAndClose(url);
            });
        } else {
            fallbackCopyAndClose(url);
        }
    }

    function showSuccessAlert() {
        Swal.fire({
            icon: 'success',
            title: 'URL Copied!',
            html: `
                <div style="text-align: center; color: #cbd5e1;">
                    <p style="margin: 0; font-size: 16px; color: #564ade;">
                        Paste the URL in the Chrome browser
                    </p>
                    <p style="margin: 10px 0 0 0; font-size: 14px; color: #94a3b8;">
                        Redirecting<span class="dots"></span>
                    </p>
                </div>
                <style>
                    .dots::after {
                        content: '';
                        animation: dots 1.5s steps(4, end) infinite;
                    }
                    @keyframes dots {
                        0%, 20% { content: '.'; }
                        40% { content: '..'; }
                        60%, 100% { content: '...'; }
                    }
                </style>
            `,
            timer: 2000,
            showConfirmButton: false,
            background: '#0f172a',
            color: '#e2e8f0',
            customClass: {
                popup: 'dark-swal-success'
            },
            didOpen: () => {
                const style = document.createElement('style');
                style.textContent = `
                    .dark-swal-success {
                        border: 1px solid #3022c5 !important;
                        box-shadow: 0 25px 50px -12px rgba(45, 34, 197, 0.3) !important;
                        border-radius: 16px !important;
                    }
                    .swal2-icon.swal2-success {
                        border-color: #4522c5 !important;
                    }
                    .swal2-success-line-tip,
                    .swal2-success-line-long {
                        background-color: #2225c5 !important;
                    }
                    .swal2-success-ring {
                        border-color: rgba(69, 34, 197, 0.3) !important;
                    }
                `;
                document.head.appendChild(style);
            },
            willClose: closeOrRedirect
        });
    }

    function fallbackCopyAndClose(url) {
        const textArea = document.createElement('textarea');
        textArea.value = url;
        textArea.style.position = 'fixed';
        textArea.style.opacity = '0';
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);

        showSuccessAlert();
    }

    function closeOrRedirect() {
        if (window.history.length > 1) {
            window.history.back();
        } else {
            window.close();
            setTimeout(() => {
                window.location.href = 'about:blank';
            }, 100);
        }
    }

    function showEnhancedBrowserWarning(browserName) {
        // Check if Swal is available
        if (typeof Swal === 'undefined') {
            console.error('SweetAlert2 not loaded! Please include: <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>');
            alert(`Please open this page in Chrome browser.\n\nDetected: ${browserName} In-App Browser`);
            return;
        }

        Swal.fire({
            icon: 'warning',
            title: 'Browser Not Supported',
            html: `
                <div style="text-align: left; color: #e2e8f0;">
                    <div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 20px; border-radius: 12px; margin-bottom: 20px; border: 1px solid #334155;">
                        <p style="margin: 0 0 10px 0; display: flex; align-items: center; gap: 8px;">
                            <span style="background: #ef4444; width: 8px; height: 8px; border-radius: 50%; display: inline-block; animation: pulse 2s infinite;"></span>
                            <strong style="color: #fefefe;">Detected:</strong> 
                            <span style="color: #4a5bde;">${browserName} In-App Browser</span>
                        </p>
                        <p style="margin: 10px 0 0 0; color: #cbd5e1; font-size: 14px;">
                            For a better experience and full functionality, open in <strong style="color: #4a4ade;">Chrome Browser</strong>.
                        </p>
                    </div>
                    
                    <div style="background: #1e293b; padding: 18px; border-radius: 12px; border-left: 4px solid #3838f8; margin-bottom: 15px;">
                        <p style="margin: 0 0 12px 0; color: #ffffff; font-weight: bold; display: flex; align-items: center; gap: 8px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                                <path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533L8.93 6.588zM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/>
                            </svg>
                            How to open in Chrome:
                        </p>
                        <ol style="padding-left: 20px; margin: 0; color: #cbd5e1; line-height: 1.8;">
                            <li style="margin-bottom: 8px;">
                                Tap the <strong style="color: #544ade;">3 dots (⋮)</strong> in the upper right corner
                            </li>
                            <li style="margin-bottom: 8px;">
                                Select <strong style="color: #604ade;">"Open in Chrome"</strong> or <strong style="color: #6a4ade;">"Open in Browser"</strong>
                            </li>
                        </ol>
                    </div>
                    
                    <div style="text-align: center; padding: 12px; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-radius: 8px; border: 1px dashed #475569;">
                        <p style="margin: 0; color: #94a3b8; font-size: 13px; font-style: italic;">
                            Or click the button below to copy the URL
                        </p>
                    </div>
                </div>
                
                <style>
                    @keyframes pulse {
                        0%, 100% { opacity: 1; }
                        50% { opacity: 0.5; }
                    }
                </style>
            `,
            showCancelButton: false,
            confirmButtonText: 'Copy URL & Close',
            allowOutsideClick: false,
            background: '#0f172a',
            color: '#000000',
            customClass: {
                popup: 'dark-swal-popup',
                confirmButton: 'dark-swal-confirm',
                title: 'dark-swal-title'
            },
            didOpen: () => {
                const style = document.createElement('style');
                style.textContent = `
                    .dark-swal-popup {
                        border: 1px solid #334155 !important;
                        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5) !important;
                        border-radius: 16px !important;
                    }
                    
                    .dark-swal-title {
                        color: #2488fb !important;
                        font-weight: 700 !important;
                        font-size: 24px !important;
                        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
                    }
                    
                    .dark-swal-confirm {
                        background: linear-gradient(135deg, #603bf6 0%, #4625eb 100%) !important;
                        border: none !important;
                        padding: 12px 32px !important;
                        font-size: 16px !important;
                        font-weight: 600 !important;
                        border-radius: 10px !important;
                        transition: all 0.3s ease !important;
                        box-shadow: 0 4px 14px 0 rgba(59, 75, 246, 0.4) !important;
                    }
                    
                    .dark-swal-confirm:hover {
                        background: linear-gradient(135deg, #3c25eb 0%, #291dd8 100%) !important;
                        transform: translateY(-2px) !important;
                        box-shadow: 0 6px 20px 0 rgba(62, 59, 246, 0.5) !important;
                    }
                    
                    .dark-swal-confirm:active {
                        transform: translateY(0px) !important;
                    }
                    
                    .swal2-icon.swal2-warning {
                        border-color: #2488fb !important;
                        color: #2488fb !important;
                    }
                `;
                document.head.appendChild(style);
            }
        }).then((result) => {
            if (result.isConfirmed) {
                copyURLAndClose();
            }
        });
    }

    // Public API
    return {
        init: function () {
            // Prevent multiple initializations
            if (window.__browserDetectorInitialized) {
                console.log('BrowserDetector already initialized');
                return;
            }

            const detection = detectInAppBrowser();

            if (detection.isInApp) {
                console.log(`In-app browser detected: ${detection.browserName}`);
                showEnhancedBrowserWarning(detection.browserName);
                console.log('User Agent:', navigator.userAgent);
            } else {
                console.log('Regular browser detected');
            }

            window.__browserDetectorInitialized = true;
        },

        // Manual trigger (optional)
        check: function () {
            return detectInAppBrowser();
        }
    };
})();

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
        BrowserDetector.init();
    });
} else {
    // DOM already loaded (script loaded after page load)
    BrowserDetector.init();
}