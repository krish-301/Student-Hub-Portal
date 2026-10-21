CREATE DATABASE IF NOT EXISTS studenthub
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE studenthub;

CREATE TABLE IF NOT EXISTS students (
    student_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    enrollment_no VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(254) NOT NULL UNIQUE,
    course VARCHAR(80) NOT NULL DEFAULT 'General',
    status ENUM('active', 'deleted') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

SET @course_column_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'course'
);
SET @course_column_sql = IF(
    @course_column_exists = 0,
    'ALTER TABLE students ADD COLUMN course VARCHAR(80) NOT NULL DEFAULT ''General''',
    'SELECT 1'
);
PREPARE add_course_column FROM @course_column_sql;
EXECUTE add_course_column;
DEALLOCATE PREPARE add_course_column;

SET @status_column_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'status'
);
SET @status_column_sql = IF(
    @status_column_exists = 0,
    'ALTER TABLE students ADD COLUMN status ENUM(''active'', ''deleted'') NOT NULL DEFAULT ''active''',
    'SELECT 1'
);
PREPARE add_status_column FROM @status_column_sql;
EXECUTE add_status_column;
DEALLOCATE PREPARE add_status_column;

CREATE TABLE IF NOT EXISTS user_accounts (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(50) NOT NULL,
    email VARCHAR(254) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    gender ENUM('Male', 'Female', 'Other') NOT NULL,
    date_of_birth DATE NOT NULL,
    branch VARCHAR(40) NOT NULL,
    semester VARCHAR(12) NOT NULL,
    mobile CHAR(10) NOT NULL,
    address VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS account_auth (
    user_id INT UNSIGNED PRIMARY KEY,
    role ENUM('student', 'admin') NOT NULL DEFAULT 'student',
    last_login_at DATETIME NULL,
    CONSTRAINT fk_account_auth_user
        FOREIGN KEY (user_id) REFERENCES user_accounts (user_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS remember_tokens (
    selector CHAR(32) PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    validator_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_remember_tokens_user (user_id),
    CONSTRAINT fk_remember_tokens_user
        FOREIGN KEY (user_id) REFERENCES user_accounts (user_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS registration_audit_logs (
    audit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(20) NOT NULL,
    action VARCHAR(40) NOT NULL,
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_registration_audit_username (username)
) ENGINE=InnoDB;

-- Demo credentials: SH1001 / Student123! and ADMIN01 / Admin123!.
-- Replace or remove these sample accounts before deploying publicly.
INSERT IGNORE INTO user_accounts
    (username, name, email, password_hash, gender, date_of_birth, branch, semester, mobile, address)
VALUES
    ('SH1001', 'Alex Morgan', 'alex@example.com',
     '$2y$10$mW6BPTJ5I9hm3oXjgdCLl.9JbzjFT/dsgvJNdf19wa3G9DY/jhodC',
     'Other', '2000-01-01', 'Computer Science', 'Semester 1', '0000000001', 'Student demo account'),
    ('ADMIN01', 'Student Hub Admin', 'admin@studenthub.local',
     '$2y$10$s1jnQOqFtEs3v/LfJ2dlm.Rg9Ee.AOHrR8zc2My3Kub3iWmwYwOZq',
     'Other', '1990-01-01', 'Computer Science', 'Semester 1', '0000000002', 'Administrator demo account');

INSERT IGNORE INTO account_auth (user_id, role)
SELECT user_id, 'student' FROM user_accounts;

UPDATE account_auth a
JOIN user_accounts u ON u.user_id = a.user_id
SET a.role = 'admin'
WHERE u.username = 'ADMIN01';

CREATE TABLE IF NOT EXISTS events (
    event_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    description TEXT,
    event_date DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS registrations (
    registration_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    event_id INT UNSIGNED NOT NULL,
    registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_event (student_id, event_id),
    CONSTRAINT fk_registrations_student
        FOREIGN KEY (student_id) REFERENCES students (student_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_registrations_event
        FOREIGN KEY (event_id) REFERENCES events (event_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO students (enrollment_no, name, email) VALUES
    ('SH1001', 'Alex Morgan', 'alex@example.com'),
    ('SH1002', 'Jamie Lee', 'jamie@example.com'),
    ('SH1003', 'Sam Patel', 'sam@example.com');

INSERT IGNORE INTO students (enrollment_no, name, email, course) VALUES
    ('ST2001', 'Taylor Brooks', 'taylor.brooks@example.com', 'Computer Science'),
    ('ST2002', 'Jordan Rivera', 'jordan.rivera@example.com', 'Information Technology'),
    ('ST2003', 'Casey Morgan', 'casey.morgan@example.com', 'Electronics'),
    ('ST2004', 'Riley Chen', 'riley.chen@example.com', 'Mechanical'),
    ('ST2005', 'Avery Patel', 'avery.patel@example.com', 'Civil'),
    ('ST2006', 'Quinn Davis', 'quinn.davis@example.com', 'Computer Science'),
    ('ST2007', 'Morgan Wilson', 'morgan.wilson@example.com', 'Information Technology'),
    ('ST2008', 'Cameron Shah', 'cameron.shah@example.com', 'Electronics'),
    ('ST2009', 'Drew Thompson', 'drew.thompson@example.com', 'Mechanical'),
    ('ST2010', 'Skyler Singh', 'skyler.singh@example.com', 'Civil'),
    ('ST2011', 'Reese Martin', 'reese.martin@example.com', 'Computer Science'),
    ('ST2012', 'Rowan Lee', 'rowan.lee@example.com', 'Information Technology'),
    ('ST2013', 'Emerson Clark', 'emerson.clark@example.com', 'Electronics'),
    ('ST2014', 'Finley Wright', 'finley.wright@example.com', 'Mechanical'),
    ('ST2015', 'Parker Scott', 'parker.scott@example.com', 'Civil'),
    ('ST2016', 'Hayden Adams', 'hayden.adams@example.com', 'Computer Science'),
    ('ST2017', 'Kendall Baker', 'kendall.baker@example.com', 'Information Technology'),
    ('ST2018', 'Dakota Young', 'dakota.young@example.com', 'Electronics'),
    ('ST2019', 'Sage Mitchell', 'sage.mitchell@example.com', 'Mechanical'),
    ('ST2020', 'Arden Turner', 'arden.turner@example.com', 'Civil');

INSERT IGNORE INTO events (event_id, title, description, event_date) VALUES
    (1, 'Web Development Workshop', 'An introduction to building websites.', '2027-01-15 10:00:00'),
    (2, 'Campus Tech Fair', 'Showcase student technology projects.', '2027-02-10 09:00:00'),
    (3, 'Career Skills Seminar', 'Practical interview and career advice.', '2027-03-05 13:00:00');

INSERT IGNORE INTO registrations (student_id, event_id) VALUES
    (1, 1),
    (1, 2),
    (2, 1),
    (3, 3);
