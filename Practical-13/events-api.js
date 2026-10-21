(() => {
    const endpoint = "../php/api/events.php";
    const form = document.getElementById("eventForm");
    const list = document.getElementById("eventList");
    const message = document.getElementById("apiMessage");
    const loading = document.getElementById("eventLoading");
    const pageInfo = document.getElementById("eventPageInfo");
    let csrfToken = "";
    let page = 1;
    let pages = 1;
    let searchTimer;

    function showMessage(text, kind = "error") {
        message.textContent = text;
        message.className = `form-message ${kind}`;
        message.hidden = false;
    }

    async function request(url, options = {}) {
        const headers = new Headers(options.headers || {});
        if (options.body && !(options.body instanceof FormData)) {
            headers.set("Content-Type", "application/json");
        }
        if (options.method && options.method !== "GET") {
            headers.set("X-CSRF-Token", csrfToken);
        }
        const response = await fetch(url, { ...options, headers, credentials: "same-origin" });
        let data;
        try {
            data = await response.json();
        } catch {
            throw new Error("The server returned an invalid response.");
        }
        if (response.status === 401) {
            window.location.replace("login.html");
            throw new Error("Please log in as an administrator.");
        }
        if (response.status === 403 && data.redirect) {
            window.location.replace(data.redirect);
            throw new Error("Redirecting to your dashboard.");
        }
        if (!response.ok || data.success !== true) {
            throw new Error(data.error || "The request failed.");
        }
        return data;
    }

    function formatDate(value) {
        const date = new Date(value.replace(" ", "T"));
        return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
    }

    function createEventCard(event) {
        const card = document.createElement("article");
        card.className = "card";
        const title = document.createElement("h3");
        title.textContent = event.title;
        const description = document.createElement("p");
        description.textContent = event.description || "No description.";
        const details = document.createElement("p");
        details.textContent = `${formatDate(event.event_date)} | ${event.category} | ${event.status}`;
        const edit = document.createElement("button");
        edit.type = "button";
        edit.textContent = "Edit";
        edit.addEventListener("click", () => startEdit(event));
        const remove = document.createElement("button");
        remove.type = "button";
        remove.textContent = "Delete";
        remove.addEventListener("click", () => deleteEvent(event.event_id));
        card.append(title, description, details, edit, remove);
        return card;
    }

    async function loadEvents() {
        loading.hidden = false;
        list.replaceChildren();
        const query = new URLSearchParams({
            search: document.getElementById("eventSearch").value.trim(),
            category: document.getElementById("categoryFilter").value,
            status: document.getElementById("statusFilter").value,
            page: String(page)
        });
        try {
            const result = await request(`${endpoint}?${query}`);
            csrfToken = result.csrf_token;
            pages = result.pagination.pages;
            page = result.pagination.page;
            result.data.forEach(event => list.append(createEventCard(event)));
            if (result.data.length === 0) {
                const empty = document.createElement("p");
                empty.textContent = "No events found.";
                list.append(empty);
            }
            pageInfo.textContent = `Page ${page} of ${pages} (${result.pagination.total} events)`;
            document.getElementById("previousEvents").disabled = page <= 1;
            document.getElementById("nextEvents").disabled = page >= pages;
            message.hidden = true;
        } catch (error) {
            showMessage(error.message);
        } finally {
            loading.hidden = true;
        }
    }

    function startEdit(event) {
        document.getElementById("eventId").value = event.event_id;
        document.getElementById("eventTitle").value = event.title;
        document.getElementById("eventDescription").value = event.description;
        document.getElementById("eventDate").value = event.event_date.replace(" ", "T").slice(0, 16);
        document.getElementById("eventCategory").value = event.category;
        document.getElementById("eventStatus").value = event.status;
        document.getElementById("eventFormTitle").textContent = "Edit event";
        document.getElementById("saveEventButton").textContent = "Save changes";
        document.getElementById("cancelEditButton").hidden = false;
        document.getElementById("eventTitle").focus();
    }

    function resetForm() {
        form.reset();
        document.getElementById("eventId").value = "";
        document.getElementById("eventFormTitle").textContent = "Add event";
        document.getElementById("saveEventButton").textContent = "Add event";
        document.getElementById("cancelEditButton").hidden = true;
    }

    async function saveEvent(event) {
        event.preventDefault();
        const eventId = document.getElementById("eventId").value;
        const payload = Object.fromEntries(new FormData(form).entries());
        delete payload.event_id;
        try {
            const result = await request(eventId ? `${endpoint}?id=${encodeURIComponent(eventId)}` : endpoint, {
                method: eventId ? "PUT" : "POST",
                body: JSON.stringify(payload)
            });
            resetForm();
            await loadEvents();
            showMessage(result.message, "success");
        } catch (error) {
            showMessage(error.message);
        }
    }

    async function deleteEvent(eventId) {
        if (!window.confirm("Delete this event?")) return;
        try {
            const result = await request(`${endpoint}?id=${encodeURIComponent(eventId)}`, { method: "DELETE" });
            await loadEvents();
            showMessage(result.message, "success");
        } catch (error) {
            showMessage(error.message);
        }
    }

    form.addEventListener("submit", saveEvent);
    document.getElementById("cancelEditButton").addEventListener("click", resetForm);
    document.getElementById("previousEvents").addEventListener("click", () => {
        page = Math.max(1, page - 1);
        loadEvents();
    });
    document.getElementById("nextEvents").addEventListener("click", () => {
        page = Math.min(pages, page + 1);
        loadEvents();
    });
    ["categoryFilter", "statusFilter"].forEach(id => {
        document.getElementById(id).addEventListener("change", () => {
            page = 1;
            loadEvents();
        });
    });
    document.getElementById("eventSearch").addEventListener("input", () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
            page = 1;
            loadEvents();
        }, 300);
    });

    loadEvents();
})();
