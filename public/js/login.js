(function () {
    'use strict';

    const form = document.getElementById('loginForm');
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    const submitBtn = document.getElementById('submitBtn');
    const statusText = document.getElementById('statusText');

    function setStatus(message, isError) {
        statusText.textContent = message;
        statusText.classList.toggle('error', !!isError);
    }

    async function checkExistingSession() {
        try {
            const res = await fetch('/api/auth/session', { credentials: 'same-origin' });
            if (!res.ok) return;
            const data = await res.json();
            if (data && data.authenticated && data.home) {
                window.location.replace(data.home);
            }
        } catch (_) {
            // no-op
        }
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        setStatus('');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Signing in...';

        try {
            const res = await fetch('/api/auth/login', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    email: emailInput.value.trim(),
                    password: passwordInput.value
                })
            });

            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) {
                setStatus(data.error || 'Login failed', true);
                return;
            }

            setStatus('Login successful. Redirecting...');
            window.location.replace(data.home || '/');
        } catch (_) {
            setStatus('Unable to login right now. Please try again.', true);
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Sign In';
            passwordInput.value = '';
        }
    });

    checkExistingSession();
})();
