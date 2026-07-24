<?php
require_once 'config.php';

try {
    // 1. Departments
    $pdo->exec("CREATE TABLE IF NOT EXISTS departments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 2. Jobs
    $pdo->exec("CREATE TABLE IF NOT EXISTS jobs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        department_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        job_level INT DEFAULT 1,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 3. Users (Admins, Evaluators, Staff)
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        prefix VARCHAR(50),
        firstname VARCHAR(100) NOT NULL,
        lastname VARCHAR(100) NOT NULL,
        username VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'evaluator', 'staff') NOT NULL DEFAULT 'staff',
        department_id INT,
        job_id INT,
        profile_image VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
        FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 4. Evaluation Hierarchies
    $pdo->exec("CREATE TABLE IF NOT EXISTS evaluation_hierarchies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        evaluatee_id INT NOT NULL,
        evaluator_id INT NOT NULL,
        level INT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (evaluatee_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (evaluator_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uq_eval (evaluatee_id, evaluator_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 5. Evaluation Criteria
    $pdo->exec("CREATE TABLE IF NOT EXISTS evaluation_criteria (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        max_score DECIMAL(5,2) NOT NULL DEFAULT 10.00,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 6. Evaluation Rounds
    $pdo->exec("CREATE TABLE IF NOT EXISTS evaluation_rounds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        target_score DECIMAL(5,2) DEFAULT 100.00,
        status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
        start_date DATE,
        end_date DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Drop old deprecated tables
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("DROP TABLE IF EXISTS evaluation_details, evaluations_assigned;");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    // 7. Evaluation Instances (One per calculate-round per evaluatee)
    $pdo->exec("CREATE TABLE IF NOT EXISTS evaluation_instances (
        id INT AUTO_INCREMENT PRIMARY KEY,
        round_id INT NOT NULL,
        evaluatee_id INT NOT NULL,
        status ENUM('pending_staff', 'pending_evaluator', 'completed') NOT NULL DEFAULT 'pending_staff',
        total_score_average DECIMAL(5,2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (round_id) REFERENCES evaluation_rounds(id) ON DELETE CASCADE,
        FOREIGN KEY (evaluatee_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uq_inst (round_id, evaluatee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 8. Evaluation Criteria Inputs (Self-assessments by staff)
    $pdo->exec("CREATE TABLE IF NOT EXISTS evaluation_criteria_inputs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        evaluation_instance_id INT NOT NULL,
        criteria_id INT NOT NULL,
        staff_input_text TEXT,
        staff_attachment VARCHAR(255),
        staff_link VARCHAR(500),
        FOREIGN KEY (evaluation_instance_id) REFERENCES evaluation_instances(id) ON DELETE CASCADE,
        FOREIGN KEY (criteria_id) REFERENCES evaluation_criteria(id) ON DELETE CASCADE,
        UNIQUE KEY uq_input (evaluation_instance_id, criteria_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 9. Evaluation Evaluator Status (Tracks each evaluator's progress)
    $pdo->exec("CREATE TABLE IF NOT EXISTS evaluation_evaluator_status (
        id INT AUTO_INCREMENT PRIMARY KEY,
        evaluation_instance_id INT NOT NULL,
        evaluator_id INT NOT NULL,
        status ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
        total_score DECIMAL(5,2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (evaluation_instance_id) REFERENCES evaluation_instances(id) ON DELETE CASCADE,
        FOREIGN KEY (evaluator_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uq_ev_status (evaluation_instance_id, evaluator_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 10. Evaluation Evaluator Scores (Details of scores by evaluator)
    $pdo->exec("CREATE TABLE IF NOT EXISTS evaluation_evaluator_scores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        evaluation_evaluator_status_id INT NOT NULL,
        criteria_id INT NOT NULL,
        score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        comment TEXT,
        FOREIGN KEY (evaluation_evaluator_status_id) REFERENCES evaluation_evaluator_status(id) ON DELETE CASCADE,
        FOREIGN KEY (criteria_id) REFERENCES evaluation_criteria(id) ON DELETE CASCADE,
        UNIQUE KEY uq_ev_score (evaluation_evaluator_status_id, criteria_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Seed default admin
    $admin_username = 'admin';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $stmt->execute([$admin_username]);
    if ($stmt->fetchColumn() == 0) {
        $admin_pass = password_hash('password', PASSWORD_DEFAULT);
        $insert = $pdo->prepare("INSERT INTO users (prefix, firstname, lastname, username, password, role) VALUES ('Mr.', 'System', 'Administrator', ?, ?, 'admin')");
        $insert->execute([$admin_username, $admin_pass]);
        echo "Default admin created (username: admin, password: password)\n";
    }

    echo "Database initialization completed successfully.\n";

} catch (PDOException $e) {
    die("Error creating tables: " . $e->getMessage());
}
?>
