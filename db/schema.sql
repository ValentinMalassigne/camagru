-- Camagru database schema.
-- Idempotent: safe to run on every start (CREATE ... IF NOT EXISTS).
-- Applied by bin/setup-db.php when the database is empty or partially set up.
-- Never drops or truncates; existing data is left untouched.

-- Users: login accounts. Username and email unique, case-insensitive.
CREATE TABLE IF NOT EXISTS users (
    id                    SERIAL PRIMARY KEY,
    username              TEXT NOT NULL,
    email                 TEXT NOT NULL,
    password_hash         TEXT NOT NULL,
    is_verified           BOOLEAN NOT NULL DEFAULT false,
    verification_token_hash TEXT,
    notify_on_comment     BOOLEAN NOT NULL DEFAULT true,
    notify_on_own_comment BOOLEAN NOT NULL DEFAULT false,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS users_username_lower_uniq ON users (lower(username));
CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_uniq    ON users (lower(email));

-- Password resets: single-use, hashed token, 1-hour expiry.
CREATE TABLE IF NOT EXISTS password_resets (
    id         SERIAL PRIMARY KEY,
    user_id    INT REFERENCES users ON DELETE CASCADE,
    token_hash TEXT NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    used_at    TIMESTAMPTZ
);

-- Images: composited pictures owned by a user.
CREATE TABLE IF NOT EXISTS images (
    id         SERIAL PRIMARY KEY,
    user_id    INT REFERENCES users ON DELETE CASCADE,
    filename   TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS images_created_at_idx ON images (created_at DESC);

-- Likes: one per (user, image). Composite primary key prevents duplicates.
CREATE TABLE IF NOT EXISTS likes (
    user_id  INT REFERENCES users ON DELETE CASCADE,
    image_id INT REFERENCES images ON DELETE CASCADE,
    PRIMARY KEY (user_id, image_id)
);

-- Comments: text on an image, owned by a user.
CREATE TABLE IF NOT EXISTS comments (
    id         SERIAL PRIMARY KEY,
    image_id   INT REFERENCES images ON DELETE CASCADE,
    user_id    INT REFERENCES users ON DELETE CASCADE,
    body       TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
