CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  empresa VARCHAR(160) NOT NULL,
  cif VARCHAR(30) NOT NULL,
  contacto VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL,
  telefono VARCHAR(40) NOT NULL,
  provincia VARCHAR(100) NOT NULL,
  tipo VARCHAR(100) NOT NULL,
  especialidad VARCHAR(190) NOT NULL,
  zona VARCHAR(255) NULL,
  password_hash VARCHAR(255) NOT NULL,
  descripcion TEXT NULL,
  web VARCHAR(255) NULL,
  founder_company TINYINT(1) NOT NULL DEFAULT 0,
  founder_started_at DATETIME NULL,
  founder_expires_at DATETIME NULL,
  founder_status VARCHAR(20) NOT NULL DEFAULT 'none',
  founder_reminder_30_sent TINYINT(1) NOT NULL DEFAULT 0,
  founder_reminder_7_sent TINYINT(1) NOT NULL DEFAULT 0,
  founder_reminder_1_sent TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_cif (cif),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_provincia (provincia),
  KEY idx_users_tipo (tipo),
  KEY idx_users_especialidad (especialidad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS opportunities (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  province VARCHAR(100) NOT NULL,
  category VARCHAR(120) NOT NULL,
  description TEXT NOT NULL,
  status ENUM('active','closed','draft') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_opp_status_created (status, created_at),
  KEY idx_opp_province (province),
  CONSTRAINT fk_opp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS subscriptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  plan VARCHAR(40) NOT NULL,
  billing_cycle VARCHAR(20) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  stripe_customer_id VARCHAR(100) NULL,
  stripe_subscription_id VARCHAR(100) NULL,
  stripe_checkout_session_id VARCHAR(120) NULL,
  current_period_end DATETIME NULL,
  cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sub_stripe_subscription (stripe_subscription_id),
  KEY idx_sub_user_status (user_id,status),
  CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket_key CHAR(64) NOT NULL,
  action VARCHAR(32) NOT NULL,
  failures INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (bucket_key),
  KEY idx_rate_action_updated (action, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS founder_slots (
  slot_no TINYINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  claimed_at DATETIME NULL,
  PRIMARY KEY (slot_no),
  UNIQUE KEY uq_founder_slot_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO founder_slots (slot_no) VALUES
(1),(2),(3),(4),(5),(6),(7),(8),(9),(10),
(11),(12),(13),(14),(15),(16),(17),(18),(19),(20);


CREATE TABLE IF NOT EXISTS invited_companies (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company VARCHAR(160) NOT NULL,
  cif VARCHAR(30) NOT NULL,
  contacto VARCHAR(160) NULL,
  email VARCHAR(190) NULL,
  telefono VARCHAR(40) NULL,
  address VARCHAR(255) NULL,
  province VARCHAR(100) NOT NULL,
  tipo VARCHAR(100) NOT NULL DEFAULT 'Empresa Invitada',
  especialidad VARCHAR(190) NULL,
  zona VARCHAR(255) NULL,
  descripcion TEXT NULL,
  web VARCHAR(255) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'invited',
  invitation_date DATETIME NOT NULL,
  registration_date DATETIME NULL,
  user_id INT UNSIGNED NULL,
  source VARCHAR(40) NOT NULL DEFAULT 'manual',
  PRIMARY KEY (id),
  UNIQUE KEY uq_invited_cif (cif),
  KEY idx_invited_province (province),
  KEY idx_invited_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_invitations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invited_company_id INT UNSIGNED NOT NULL,
  email_destino VARCHAR(190) NOT NULL,
  contacto VARCHAR(160) NULL,
  token_hash CHAR(64) NOT NULL,
  founder_slot_no TINYINT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  sent_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_company_invitation_token (token_hash),
  KEY idx_company_invitation_status (status,expires_at),
  KEY idx_company_invitation_company (invited_company_id),
  KEY idx_company_invitation_founder_slot (founder_slot_no),
  CONSTRAINT fk_company_invited FOREIGN KEY (invited_company_id) REFERENCES invited_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS contact_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  target_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
