function validateLogin() {
    let username = document.getElementById("username").value.trim();
    let password = document.getElementById("password").value.trim();

    if (username === "") {
        alert("Username is required");
        return false;
    }

    if (password.length < 6) {
        alert("Password must be at least 6 characters");
        return false;
    }

    alert("Login Successful!");
    return true;
}

function validateRegister() {
    let fullname = document.getElementById("fullname").value.trim();
    let studentid = document.getElementById("studentid").value.trim();
    let mobile = document.getElementById("mobile").value.trim();
    let email = document.getElementById("email").value.trim();
    let division = document.getElementById("division").value;

    if (fullname === "") {
        alert("Full Name is required");
        return false;
    }

    if (studentid === "") {
        alert("Student ID is required");
        return false;
    }

    if (!/^[0-9]{10}$/.test(mobile)) {
        alert("Enter a valid 10-digit Mobile Number");
        return false;
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        alert("Enter a valid Email Address");
        return false;
    }

    if (division === "") {
        alert("Division is required");
        return false;
    }

    alert("Registration Successful!");
    return true;
}

function result(event) {

    if (event) {
        event.preventDefault();
    }

    const name = document.getElementById("name");
    const degree = document.getElementById("degree");
    const sem = document.getElementById("sem");
    const exam = document.getElementById("exam");
    const id = document.getElementById("ID");

    const nameValue = name.value.trim();
    const degreeValue = degree.value.trim();
    const semValue = sem.value.trim();
    const examValue = exam.value.trim();
    const idValue = id.value.trim();

    name.style.border = "1px solid #ccc";
    degree.style.border = "1px solid #ccc";
    sem.style.border = "1px solid #ccc";
    exam.style.border = "1px solid #ccc";
    id.style.border = "1px solid #ccc";

    let isValid = true;

    if (nameValue === "" || nameValue === "Select Institute") {
        name.style.border = "2px solid red";
        isValid = false;
    }

    if (degreeValue === "" || degreeValue === "Select Degree") {
        degree.style.border = "2px solid red";
        isValid = false;
    }

    if (semValue === "" || semValue === "Select Semester") {
        sem.style.border = "2px solid red";
        isValid = false;
    }

    if (examValue === "" || examValue === "Select Month") {
        exam.style.border = "2px solid red";
        isValid = false;
    }

    if (idValue === "") {
        id.style.border = "2px solid red";
        isValid = false;
    }

    if (!isValid) {
        showNotification("Please fill in all required fields.");
        return false;
    }

    showNotification("Result retrieved successfully!");

    setTimeout(function() {
        window.location.href = "resultpage.html";
    }, 1000);

    return false;
}


/*-------------- Clear Form ----------------*/

function clearForm() {
    const form = document.querySelector("form");

    if (form) {
        form.reset();

        const inputs = form.querySelectorAll("input, select, textarea");

        inputs.forEach(function(input) {
            input.style.border = "1px solid #ccc";
        });

        showNotification("Form cleared successfully!");
    }
}


/*-------------- Contact Page ----------------*/

function contact() {

    const name = document.getElementById("name");
    const message = document.getElementById("message");

    const nameValue = name.value.trim();
    const messageValue = message.value.trim();

    name.style.border = "1px solid #ccc";
    message.style.border = "1px solid #ccc";

    if (nameValue === "" || messageValue === "") {

        if (nameValue === "") {
            name.style.border = "2px solid red";
        }

        if (messageValue === "") {
            message.style.border = "2px solid red";
        }

        showNotification("Please enter both name and message.");
        return false;
    }

    showNotification("Message sent successfully!");

    name.value = "";
    message.value = "";

    return false;
}

function showNotification(message) {
    const notification = document.getElementById("notification");
    const notificationMessage = document.getElementById("notificationMessage");

    if (!notification || !notificationMessage) {
        return;
    }

    notificationMessage.textContent = message;

    notification.classList.add("show");

    setTimeout(function() {
        notification.classList.remove("show");
    }, 3000);
}

function toggleMenu() {
    const navbar = document.getElementById("navbar");
    const menuButton = document.getElementById("menuButton");

    navbar.classList.toggle("active");

    if (navbar.classList.contains("active")) {
        menuButton.textContent = "✕";
    } else {
        menuButton.textContent = "☰";
    }
}

function initTheme() {
    const savedTheme = localStorage.getItem("theme") || "light";
    const body = document.body;
    const themeButton = document.getElementById("themeBtn");

    if (savedTheme === "dark") {
        body.classList.add("dark-theme");
        if (themeButton) themeButton.textContent = "☀️ Theme";
    } else {
        body.classList.remove("dark-theme");
        if (themeButton) themeButton.textContent = "🌙 Theme";
    }
}

function toggleTheme() {
    const body = document.body;
    const themeButton = document.getElementById("themeBtn");

    body.classList.toggle("dark-theme");

    if (body.classList.contains("dark-theme")) {
        localStorage.setItem("theme", "dark");
        if (themeButton) themeButton.textContent = "☀️ Theme";
    } else {
        localStorage.setItem("theme", "light");
        if (themeButton) themeButton.textContent = "🌙 Theme";
    }
}

window.addEventListener("DOMContentLoaded", function() {
    initTheme();
    const themeBtn = document.getElementById("themeBtn");
    if (themeBtn) {
        themeBtn.addEventListener("click", toggleTheme);
    }
});
