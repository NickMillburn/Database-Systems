import hashlib
import hmac
import os
import sqlite3

DB_NAME = "auth_demo.db"


def get_db_connection():
    """Establishes and returns a database connection."""
    conn = sqlite3.connect(DB_NAME)
    # Return rows as dictionaries for clean attribute access
    conn.row_factory = sqlite3.Row
    return conn


def init_db():
    """Creates the users table if it does not already exist."""
    with get_db_connection() as conn:
        conn.execute(
            """
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash BLOB NOT NULL,
                salt BLOB NOT NULL
            )
        """
        )
        conn.commit()


def hash_password(password: str, salt: bytes = None) -> tuple[bytes, bytes]:
    """Hashes a password using PBKDF2 with a secure salt."""
    if salt is None:
        salt = os.urandom(16)  # Generate a random 16-byte salt

    # Use PBKDF2-HMAC-SHA256 to safely hash the password
    pwd_hash = hashlib.pbkdf2_hmac(
        hash_name="sha256",
        password=password.encode("utf-8"),
        salt=salt,
        iterations=100_000,
    )
    return pwd_hash, salt


def register_user(username: str, password: str) -> bool:
    """Registers a new user with a uniquely hashed password."""
    pwd_hash, salt = hash_password(password)

    try:
        with get_db_connection() as conn:
            conn.execute(
                "INSERT INTO users (username, password_hash, salt) VALUES (?, ?, ?)",
                (username, pwd_hash, salt),
            )
            conn.commit()
        print(f"✔️ User '{username}' registered successfully.")
        return True
    except sqlite3.IntegrityError:
        print(f"❌ Registration failed: Username '{username}' already exists.")
        return False


def login_user(username: str, password: str) -> bool:
    """Authenticates a user against stored hash values."""
    with get_db_connection() as conn:
        # Fetch user matching the username using parameterized queries to prevent SQL injection
        cursor = conn.execute(
            "SELECT password_hash, salt FROM users WHERE username = ?",
            (username,),
        )
        user = cursor.fetchone()

    if user is None:
        print("❌ Login failed: Invalid username or password.")
        return False

    # Hash the input password using the retrieved salt
    stored_hash = user["password_hash"]
    stored_salt = user["salt"]
    input_hash, _ = hash_password(password, stored_salt)

    # Use constant-time comparison to prevent timing attacks
    if hmac.compare_digest(stored_hash, input_hash):
        print(f"🔓 Welcome back, {username}! Login successful.")
        return True
    else:
        print("❌ Login failed: Invalid username or password.")
        return False


# --- Execution Example ---
if __name__ == "__main__":
    # Initialize the database schema
    init_db()

    print("--- Registration Test ---")
    register_user("bob_dev", "abc123")
    register_user("bob_dev", "DuplicateUserCheck")  # should fail gracefully

    print("\n--- Login Test ---")
    login_user("bob_dev", "WrongPassword")  # Fails authentication
    login_user("bob_user", "abc123")  # Fails authentication
    login_user("alice_dev", "SuperSecret123")  # Succeeds
    login_user("bob_dev", "abc123")  # Succeeds