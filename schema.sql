-- Vokabeltrainer - Datenbankschema (MySQL 8 / MariaDB 10.4+)
-- Einspielen:  mysql -u USER -p DBNAME < schema.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(64)  NOT NULL,
  display_name  VARCHAR(64)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  color         CHAR(7)      NOT NULL DEFAULT '#4f7cff',
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ein Datensatz pro Homescreen-Icon. Gespeichert wird nur der SHA-256-Hash.
CREATE TABLE IF NOT EXISTS device_tokens (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  token_hash   CHAR(64)     NOT NULL,
  label        VARCHAR(128) NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME     NULL,
  revoked_at   DATETIME     NULL,
  UNIQUE KEY uq_dt_hash (token_hash),
  KEY idx_dt_user (user_id),
  CONSTRAINT fk_dt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS languages (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(64)  NOT NULL,
  flag_emoji VARCHAR(16)  NOT NULL DEFAULT '',
  -- ISO-Kürzel (fr, en, la, da) als Tastaturhinweis im Lückentext; darf fehlen.
  code       VARCHAR(8)   NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lang_user_name (user_id, name),
  CONSTRAINT fk_lang_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lerneinheit ("Unit 1", "Lektion 3")
CREATE TABLE IF NOT EXISTS units (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  language_id INT UNSIGNED NOT NULL,
  title       VARCHAR(128) NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Die Lueckensaetze entstehen im Hintergrund, gleich nach dem Einlesen.
  -- NULL = nie angestossen, sonst running / done / failed.
  sentences_status     VARCHAR(16)  NULL,
  sentences_started_at DATETIME     NULL,
  sentences_error      VARCHAR(255) NULL,
  KEY idx_units_user (user_id),
  KEY idx_units_lang (language_id),
  CONSTRAINT fk_units_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_units_lang FOREIGN KEY (language_id) REFERENCES languages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vocab (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  unit_id      INT UNSIGNED NOT NULL,
  term_foreign VARCHAR(255) NOT NULL,
  term_native  VARCHAR(255) NOT NULL,
  note         VARCHAR(255) NULL,
  -- Wortart, vom Modell beim Einlesen bestimmt; NULL = noch nicht bestimmt.
  word_type    VARCHAR(16)  NULL,
  position     INT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_vocab_unit (unit_id, position),
  CONSTRAINT fk_vocab_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lückensätze für den Lückentext-Trainer. foreign_text enthält genau einmal
-- den Platzhalter {}; answer ist die dort erwartete Form - nicht zwingend die
-- Vokabel selbst ("s'appeler" wird im Satz zu "Je m'appelle").
CREATE TABLE IF NOT EXISTS sentences (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vocab_id     INT UNSIGNED NOT NULL,
  native_text  VARCHAR(255) NOT NULL,
  foreign_text VARCHAR(255) NOT NULL,
  answer       VARCHAR(128) NOT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sentences_vocab (vocab_id),
  CONSTRAINT fk_sentences_vocab FOREIGN KEY (vocab_id) REFERENCES vocab(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lernstand pro Vokabel und Trainer-Variante (mode): 'mc' und 'cloze'.
-- Gemeldete Lückensätze: eine Zeile je Kind und Satz, damit niemand
-- denselben Satz mehrfach meldet. Das Getippte kommt mit, weil es meist
-- entscheidet, ob der Satz oder die erwartete Antwort daneben lag.
CREATE TABLE IF NOT EXISTS sentence_flags (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sentence_id INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  typed       VARCHAR(128) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_flag (sentence_id, user_id),
  KEY idx_flag_sentence (sentence_id),
  CONSTRAINT fk_flag_sentence FOREIGN KEY (sentence_id) REFERENCES sentences(id) ON DELETE CASCADE,
  CONSTRAINT fk_flag_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS progress (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  vocab_id      INT UNSIGNED NOT NULL,
  mode          VARCHAR(16)  NOT NULL DEFAULT 'mc',
  streak        INT UNSIGNED NOT NULL DEFAULT 0,
  correct_count INT UNSIGNED NOT NULL DEFAULT 0,
  wrong_count   INT UNSIGNED NOT NULL DEFAULT 0,
  known_at      DATETIME     NULL,
  last_seen_at  DATETIME     NULL,
  -- Der Lernstand gehoert dem Kind. Frueher stand hier (vocab_id, mode) ohne
  -- user_id - solange jede Vokabel einem Kind gehoerte, trug das. Sobald sich
  -- mehrere Kinder einen Vokabelsatz teilen, teilen sie sonst auch die Serie.
  UNIQUE KEY uq_progress_user (user_id, vocab_id, mode),
  -- Deckt den Fremdschluessel auf vocab_id ab, den frueher uq_progress trug.
  KEY idx_progress_vocab (vocab_id, mode),
  CONSTRAINT fk_progress_user  FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_progress_vocab FOREIGN KEY (vocab_id) REFERENCES vocab(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kostenprotokoll. user_id wird beim Löschen eines Accounts auf NULL gesetzt,
-- damit die Abrechnungshistorie erhalten bleibt.
CREATE TABLE IF NOT EXISTS ai_requests (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NULL,
  user_label        VARCHAR(64)   NOT NULL DEFAULT '',
  model             VARCHAR(64)   NOT NULL,
  purpose           VARCHAR(32)   NOT NULL DEFAULT 'vocab_ocr',
  input_tokens      INT UNSIGNED  NOT NULL DEFAULT 0,
  output_tokens     INT UNSIGNED  NOT NULL DEFAULT 0,
  cache_read_tokens INT UNSIGNED  NOT NULL DEFAULT 0,
  cache_write_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  image_count       INT UNSIGNED  NOT NULL DEFAULT 0,
  entry_count       INT UNSIGNED  NOT NULL DEFAULT 0,
  cost_usd          DECIMAL(12,6) NOT NULL DEFAULT 0,
  duration_ms       INT UNSIGNED  NOT NULL DEFAULT 0,
  status            VARCHAR(16)   NOT NULL DEFAULT 'ok',
  error             TEXT          NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ai_created (created_at),
  KEY idx_ai_user (user_id),
  CONSTRAINT fk_ai_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Voreinstellungen. Preise in USD pro 1 Mio. Token, im Admin editierbar.
INSERT INTO settings (k, v) VALUES
  ('vision_model',        'claude-opus-5'),
  ('vision_effort',       'medium'),
  ('sentence_model',      'claude-sonnet-5'),
  ('sentences_per_vocab', '3'),
  ('usd_eur',             '0.92'),
  ('monthly_cost_cap_usd','10.00'),
  ('imports_per_hour',    '20'),
  ('prices_json', '{"claude-opus-5":{"in":5,"out":25,"cache_read":0.5,"cache_write":6.25},"claude-opus-4-8":{"in":5,"out":25,"cache_read":0.5,"cache_write":6.25},"claude-sonnet-5":{"in":2,"out":10,"cache_read":0.2,"cache_write":2.5},"claude-haiku-4-5":{"in":1,"out":5,"cache_read":0.1,"cache_write":1.25}}')
ON DUPLICATE KEY UPDATE k = k;
