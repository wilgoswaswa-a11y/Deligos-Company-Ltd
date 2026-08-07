window.showToast = function(message, type = 'info') {
    const container = document.getElementById('appToastContainer');
    if (!container || !message) {
        return;
    }

    const styles = {
        success: { className: 'text-bg-success', icon: 'bi-check-circle-fill' },
        error: { className: 'text-bg-danger', icon: 'bi-exclamation-triangle-fill' },
        danger: { className: 'text-bg-danger', icon: 'bi-exclamation-triangle-fill' },
        warning: { className: 'text-bg-warning', icon: 'bi-exclamation-circle-fill' },
        info: { className: 'text-bg-primary', icon: 'bi-info-circle-fill' }
    };
    const style = styles[type] || styles.info;
    const toast = document.createElement('div');
    toast.className = `toast app-toast align-items-center border-0 ${style.className}`;
    toast.role = 'alert';
    toast.ariaLive = 'assertive';
    toast.ariaAtomic = 'true';
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                <i class="bi ${style.icon} me-2"></i>${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    `;
    container.appendChild(toast);

    const instance = new bootstrap.Toast(toast, { delay: 3500 });
    toast.addEventListener('hidden.bs.toast', () => toast.remove());
    instance.show();
};

document.querySelectorAll('.alert').forEach(alertBox => {
    const message = alertBox.textContent.trim();
    if (!message) {
        return;
    }

    const type = alertBox.classList.contains('alert-success') ? 'success'
        : alertBox.classList.contains('alert-danger') ? 'error'
        : alertBox.classList.contains('alert-warning') ? 'warning'
        : 'info';
    window.showToast(message, type);
});

(() => {
    const validateEmailInput = input => {
        if (!input) {
            return;
        }

        const value = input.value.trim();
        if (!value) {
            input.setCustomValidity('');
            return;
        }

        input.setCustomValidity(/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) ? '' : 'Please enter a valid email address.');
    };

    document.querySelectorAll('input[type="email"]').forEach(input => {
        input.addEventListener('input', () => validateEmailInput(input));
        input.addEventListener('blur', () => validateEmailInput(input));
        input.addEventListener('change', () => validateEmailInput(input));
        validateEmailInput(input);
    });
})();

(() => {
    'use strict';
    const forms = document.querySelectorAll('.needs-validation');
    Array.prototype.slice.call(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
})();

/* Loading states for forms and links. */
(() => {
    const setButtonLoading = (button, label) => {
        if (!button || button.dataset.loading === 'true') {
            return;
        }

        button.dataset.loading = 'true';
        button.dataset.originalHtml = button.innerHTML;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${label}`;
    };

    document.querySelectorAll('form[data-loading-text]').forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                return;
            }

            const submitter = event.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
            if (!submitter || form.dataset.submitting === 'true') {
                if (form.dataset.submitting === 'true') {
                    event.preventDefault();
                }
                return;
            }

            form.dataset.submitting = 'true';
            form.classList.add('is-loading');
            setButtonLoading(submitter, form.dataset.loadingText || 'Saving…');
        });
    });

    document.querySelectorAll('[data-loading-link]').forEach(link => {
        link.addEventListener('click', () => {
            if (link.dataset.loading === 'true') {
                return;
            }

            link.dataset.loading = 'true';
            link.setAttribute('aria-busy', 'true');
            link.classList.add('is-loading');
            link.dataset.originalHtml = link.innerHTML;
            link.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${link.dataset.loadingText || 'Loading…'}`;
        });
    });
})();

/* Show/hide password toggles (shared across login pages and admin forms). */
(() => {
    const togglePassword = button => {
        const input = document.getElementById(button.dataset.target);
        if (!input) {
            return;
        }
        const icon = button.querySelector('i');
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        if (icon) {
            icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
        }
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    };

    document.addEventListener('click', event => {
        const button = event.target.closest('.toggle-password');
        if (button) {
            togglePassword(button);
        }
    });

    document.querySelectorAll('.password-input').forEach(input => {
        const hint = document.getElementById(input.dataset.hintId);
        if (!hint) {
            return;
        }
        input.addEventListener('focus', () => {
            hint.style.display = 'block';
        });
        input.addEventListener('click', () => {
            hint.style.display = 'block';
        });
        input.addEventListener('blur', () => {
            hint.style.display = 'none';
        });
    });
})();
