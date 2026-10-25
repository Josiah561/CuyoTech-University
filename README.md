# CuyoTech University — Student Services Information System (SSIS)

[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Database](https://img.shields.io/badge/MySQL-PDO-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Web Server](https://img.shields.io/badge/Server-Apache%20%2F%20XAMPP-FB7A24?logo=apache&logoColor=white)](https://www.apachefriends.org/)
[![License](https://img.shields.io/badge/License-Academic%20Project-blue.svg)](#)

A centralized web-based Student Services Information System engineered to automate and digitize manual university front-line workflows, eliminating physical queues for student enrollment, semester-end clearances, faculty grade encoding, cashier remittances, and official credential requests.

---

## 🏛️ System Modules & Features

### 1. Student Portal (`/student`)
- **Interactive Dashboard**: Real-time academic standing, enrolled program, and summary KPI cards.
- **Grades & Evaluation Slip**: Term-based subject load (`enrolled_subject`) with confidential draft protection and printable evaluation slip.
- **Online Document Requests**: Request Transcripts of Records (TOR), Certificates of Registration (COR), and Good Moral Certifications with automatic fee assessment and claim routing slips with barcode tracking.
- **5-Office Clearance Ledger**: Real-time visual tracking of clearances across Department, Library, Cashier, Registrar, and Student Affairs.
- **Live Notifications**: Unread counter and notification alerts for released documents and administrative status changes.

### 2. Department Module (`/dept`)
- **College Scoping**: Enforces strict departmental boundaries (e.g., College of Computer Studies).
- **Security Access Control**: Automatic HTTP 403 Forbidden enforcement and audit logging on unauthorized cross-department clearance tampering.
- **Laboratory Liability Sign-off**: Fast clearance sign-off with audit logging.

### 3. Registrar Module (`/registrar`)
- **3-Point Matriculation Check**: Validates curriculum study load, minimum tuition downpayment compliance, and complete 5-office clearance before enrollment confirmation.
- **Secure Grade Encoding**: Strict separation between "Save Draft" and "Publish", with automatic revision tracking in `grade_history`.
- **Document Release Queue**: Payment verification gate preventing document release prior to fee remittance, with automatic cryptographic verification code generation (`CTU-VER-XXXX`).

### 4. Cashier Module (`/cashier`)
- **Targeted Remittance Terminal**: Post payments toward student tuition downpayments or specific document request orders.
- **Automatic Receipt Issuance**: Instant Official Receipt (OR) generation with recalculation of balances and automatic Cashier clearance toggling.

### 5. Administrator Console (`/admin`)
- **Autonomous Clearance Sign-Off**: Centralized approval for Library and Student Affairs clearances.
- **User Management & Security Hardening**: 8+ character password policy, `must_change_password` first-login flag, and sanitization of plaintext credentials from system banners.
- **Audit Trails**: Complete chronological audit log of all administrative actions.

### 6. Public Credential Verification Gateway (`/verify.php`)
- **Zero-Login Authentication**: Allows employers, embassies, and academic institutions to verify document authenticity by entering the verification code or scanning the QR code.
- **Data Privacy Act (DPA 2012) Compliance**: Validates document legitimacy without exposing sensitive grades or personal contact details.

---

## 🚀 Installation & Local Setup

1. **Prerequisites**:
   - [XAMPP](https://www.apachefriends.org/) (Apache + MySQL with PHP 8.0 or higher).
2. **Clone / Copy into `htdocs`**:
   ```bash
   cd C:/xampp/htdocs
   git clone https://github.com/Josiah561/CuyoTech-University.git
   ```
3. **Database Configuration**:
   - Start **Apache** and **MySQL** in XAMPP Control Panel.
   - Open **phpMyAdmin** (`http://localhost/phpmyadmin`).
   - Create a new database named `ssis`.
   - Import `database/database.sql`.
4. **Launch Application**:
   - Open browser and navigate to:
     ```
     http://localhost/CuyoTech-University/login.php
     ```

---

## 🔑 Pre-Seeded Demonstration Accounts

| Role | Username | Password | Full Name / Description |
| :--- | :--- | :--- | :--- |
| **Student** | `2023-00418` | `stud123` | Juan Santos (BSIT 3A) |
| **Dept Head** | `dept01` | `pass123` | Dr. Gima Montecillo (College of Computer Studies) |
| **Registrar** | `reg01` | `pass123` | Dr. George F. Barundia |
| **Cashier** | `cash01` | `pass123` | Ms. April B. Galang |
| **Admin** | `admin01` | `pass123` | Josiah Bandong |

---

## 🛡️ Security Implementation
- **SQL Injection Prevention**: 100% prepared statements using PDO (`q()`, `row()`, `rows()`).
- **Cross-Site Scripting (XSS)**: Strict escaping of all output via `e()` helper.
- **Cross-Site Request Forgery (CSRF)**: Dynamic CSRF tokens enforced on all POST endpoints (`csrf_check()`).
- **Authentication Security**: Brute-force rate limiting, session regeneration, and BCrypt password hashing.
- **Access Control**: Role-based access control (`require_role()`) and departmental scoping.

---

## 👥 Authors & Group Members
Developed for **CuyoTech University** by:
- **Josiah Bandong** ([@Josiah561](https://github.com/Josiah561)) & Group Members
