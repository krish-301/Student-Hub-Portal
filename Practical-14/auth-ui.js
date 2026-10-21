async function loadCsrfToken(form) {
    const submit = form.querySelector("[type='submit']");
    if (submit) submit.disabled = true;

    try {
        const response = await fetch("../php/csrf-token.php", { credentials: "same-origin" });
        if (!response.ok) throw new Error("Unable to initialize the secure form.");
        const data = await response.json();
        form.querySelector("[name='csrf_token']").value = data.csrf_token;
        if (submit) submit.disabled = false;
    } catch (error) {
        const message = document.getElementById("formMessage");
        if (message) {
            message.textContent = error.message;
            message.classList.add("error");
            message.hidden = false;
        }
    }
}

async function guardDashboard() {
    const body = document.body;
    const role = body.dataset.authRole;
    if (!role) return;

    const content = document.getElementById("authContent");
    const message = document.getElementById("authMessage");
    try {
        const status = new URLSearchParams(window.location.search).get("status") || "";
        const query = new URLSearchParams({ role, status });
        const response = await fetch(`../php/dashboard-content.php?${query}`, {
            credentials: "same-origin"
        });
        if (response.status === 401 || response.status === 403) {
            const data = await response.json();
            if (response.status === 403 && data.redirect) {
                window.location.replace(data.redirect);
                return;
            }
            const status = ["timeout", "inactive"].includes(data.reason) ? `?status=${data.reason}` : "";
            window.location.replace(`login.html${status}`);
            return;
        }
        if (!response.ok) throw new Error("Could not verify your session.");
        if (content) content.innerHTML = await response.text();
        if (content) content.hidden = false;
        if (message) message.hidden = true;
    } catch (error) {
        if (message) {
            message.textContent = error.message;
            message.hidden = false;
        }
    }
}

document.querySelectorAll("form[data-csrf-form]").forEach(loadCsrfToken);
guardDashboard();
