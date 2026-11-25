document.addEventListener('DOMContentLoaded', () => {
    const claimForm = document.getElementById('claimForm');
    const claimAlert = document.getElementById('claimAlert');
    const walletForm = document.getElementById('walletForm');
    const walletAlert = document.getElementById('walletAlert');
    const walletDisplay = document.getElementById('walletDisplay');
    const redeemAlert = document.getElementById('redeemAlert');

    function showAlert(el, message, isSuccess) {
        el.textContent = message;
        el.className = 'alert ' + (isSuccess ? 'alert-success' : 'alert-error');
        el.classList.remove('hidden');
    }

    async function loadWallet(phone) {
        if (!phone) return;
        document.getElementById('wallet_phone').value = phone;
        try {
            const res = await fetch(`../api/customer_wallet.php?phone=${encodeURIComponent(phone)}`);
            const data = await res.json();

            if (!data.success) {
                walletDisplay.classList.add('hidden');
                showAlert(walletAlert, data.message, false);
                return;
            }

            walletAlert.classList.add('hidden');
            walletDisplay.classList.remove('hidden');
            document.getElementById('wName').textContent = data.customer.full_name;
            document.getElementById('wPhone').textContent = 'Mobile: ' + data.customer.phone;
            document.getElementById('wBalance').textContent = data.customer.points_balance;

            const claimsList = document.getElementById('wClaimsList');
            claimsList.innerHTML = data.claims.length
                ? data.claims.map(c => `<li><strong>+${c.points_value} pts</strong> — ${c.product_name} (<code>${c.code_plain}</code>)</li>`).join('')
                : '<li>No box claims recorded yet.</li>';

            const redList = document.getElementById('wRedemptionsList');
            redList.innerHTML = data.redemptions.length
                ? data.redemptions.map(r => `<li><strong>${r.title}</strong> (${r.points_spent} pts) — Voucher: <code>${r.voucher_code}</code> [${r.status.toUpperCase()}]</li>`).join('')
                : '<li>No rewards redeemed yet.</li>';
        } catch (err) {
            showAlert(walletAlert, 'Network error loading wallet.', false);
        }
    }

    if (claimForm) {
        claimForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(claimForm);
            const payload = Object.fromEntries(formData.entries());

            try {
                const res = await fetch('../api/claim_code.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                showAlert(claimAlert, data.message, data.success);

                if (data.success) {
                    document.getElementById('box_code').value = '';
                    loadWallet(payload.phone);
                }
            } catch (err) {
                showAlert(claimAlert, 'Error connecting to verification server.', false);
            }
        });
    }

    if (walletForm) {
        walletForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const phone = document.getElementById('wallet_phone').value.trim();
            loadWallet(phone);
        });
    }

    document.querySelectorAll('.redeem-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const phone = document.getElementById('wallet_phone').value.trim() || document.getElementById('phone').value.trim();
            if (!phone) {
                showAlert(redeemAlert, 'Please enter your registered mobile number in the Claim or Wallet form first.', false);
                return;
            }

            const rewardId = btn.dataset.rewardId;
            const title = btn.dataset.rewardTitle;
            const pts = btn.dataset.points;
            const csrf = btn.dataset.csrf;

            if (!confirm(`Redeem "${title}" for ${pts} points using mobile ${phone}?`)) {
                return;
            }

            try {
                const res = await fetch('../api/redeem_reward.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        phone: phone,
                        reward_id: rewardId,
                        csrf_token: csrf
                    })
                });
                const data = await res.json();
                showAlert(redeemAlert, data.message, data.success);
                if (data.success) {
                    loadWallet(phone);
                }
            } catch (err) {
                showAlert(redeemAlert, 'Error processing redemption.', false);
            }
        });
    });
});
