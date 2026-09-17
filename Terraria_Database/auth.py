import hashlib
import hmac
import os
import re
import sqlite3
import tkinter as tk
from datetime import date, datetime
from tkinter import ttk

HERE = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.path.join(HERE, "users.db")

# OWASP's current recommendation for PBKDF2-HMAC-SHA256
HASH_ITERATIONS = 600_000
MIN_PASSWORD_LENGTH = 8

# Same class names as the weapons table in terraria.db so the two tables can be joined later
CLASSES = ["Melee", "Ranger", "Mage", "Summoner"]
STATES = [
    "AL", "AK", "AZ", "AR", "CA", "CO", "CT", "DC", "DE", "FL", "GA", "HI", "ID", "IL",
    "IN", "IA", "KS", "KY", "LA", "ME", "MD", "MA", "MI", "MN", "MS", "MO", "MT", "NE",
    "NV", "NH", "NJ", "NM", "NY", "NC", "ND", "OH", "OK", "OR", "PA", "RI", "SC", "SD",
    "TN", "TX", "UT", "VT", "VA", "WA", "WV", "WI", "WY",
]
USERNAME_PATTERN = re.compile(r"[A-Za-z0-9_]{3,30}")
EMAIL_PATTERN = re.compile(r"[^@\s]+@[^@\s]+\.[^@\s]+")


class AccountError(Exception):
    pass


def initialize_database(path=DB_PATH):
    conn = sqlite3.connect(path)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")

    # Password hashes stay in users so queries on profiles never pull them in
    conn.executescript("""
        CREATE TABLE IF NOT EXISTS users (
            user_id        INTEGER  PRIMARY KEY AUTOINCREMENT,
            username       TEXT     NOT NULL UNIQUE COLLATE NOCASE,
            password_hash  BLOB     NOT NULL,
            salt           BLOB     NOT NULL,
            created_at     TEXT     NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS profiles (
            user_id         INTEGER  PRIMARY KEY REFERENCES users(user_id) ON DELETE CASCADE,
            first_name      TEXT     NOT NULL,
            last_name       TEXT     NOT NULL,
            email           TEXT     NOT NULL UNIQUE COLLATE NOCASE,
            date_of_birth   DATE     NOT NULL CHECK (date_of_birth = date(date_of_birth)),
            height_inches   INTEGER  NOT NULL CHECK (height_inches BETWEEN 36 AND 96),
            annual_income   INTEGER  NOT NULL CHECK (annual_income >= 0),
            state           TEXT     NOT NULL CHECK (length(state) = 2),
            smoker          INTEGER  NOT NULL CHECK (smoker IN (0, 1)),
            favorite_class  TEXT     NOT NULL CHECK (favorite_class IN ('Melee', 'Ranger', 'Mage', 'Summoner')),
            hours_played    INTEGER  NOT NULL CHECK (hours_played >= 0),
            updated_at      TEXT     NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    """)
    return conn


def hash_password(password, salt=None):
    if salt is None:
        salt = os.urandom(16)
    pwd_hash = hashlib.pbkdf2_hmac("sha256", password.encode("utf-8"), salt, HASH_ITERATIONS)
    return pwd_hash, salt


def create_account(conn, username, password, profile):
    pwd_hash, salt = hash_password(password)
    try:
        # One transaction, so a failed profile insert also rolls back the users row
        with conn:
            cursor = conn.execute(
                "INSERT INTO users (username, password_hash, salt) VALUES (?, ?, ?)",
                (username, pwd_hash, salt),
            )
            user_id = cursor.lastrowid
            conn.execute("""
                INSERT INTO profiles (
                    user_id, first_name, last_name, email, date_of_birth, height_inches,
                    annual_income, state, smoker, favorite_class, hours_played
                )
                VALUES (
                    :user_id, :first_name, :last_name, :email, :date_of_birth, :height_inches,
                    :annual_income, :state, :smoker, :favorite_class, :hours_played
                )
            """, {"user_id": user_id, **profile})
    except sqlite3.IntegrityError as err:
        if "users.username" in str(err):
            raise AccountError(f"The username '{username}' is already taken.") from err
        if "profiles.email" in str(err):
            raise AccountError("That email address already belongs to another account.") from err
        raise
    return user_id


def sign_in(conn, username, password):
    user = conn.execute(
        "SELECT user_id, password_hash, salt FROM users WHERE username = ?",
        (username,),
    ).fetchone()

    if user is None:
        # Hash anyway so a wrong username takes as long as a wrong password
        hash_password(password)
        return None

    input_hash, _ = hash_password(password, user["salt"])
    if hmac.compare_digest(user["password_hash"], input_hash):
        return user["user_id"]
    return None


def get_profile(conn, user_id):
    return conn.execute("""
        SELECT
            u.username,
            u.created_at,
            p.first_name,
            p.last_name,
            p.email,
            p.date_of_birth,
            p.height_inches,
            p.annual_income,
            p.state,
            p.smoker,
            p.favorite_class,
            p.hours_played,
            p.updated_at
        FROM users AS u
        JOIN profiles AS p ON p.user_id = u.user_id
        WHERE u.user_id = ?
    """, (user_id,)).fetchone()


def update_profile(conn, user_id, profile):
    try:
        with conn:
            conn.execute("""
                UPDATE profiles
                SET first_name     = :first_name,
                    last_name      = :last_name,
                    email          = :email,
                    date_of_birth  = :date_of_birth,
                    height_inches  = :height_inches,
                    annual_income  = :annual_income,
                    state          = :state,
                    smoker         = :smoker,
                    favorite_class = :favorite_class,
                    hours_played   = :hours_played,
                    updated_at     = CURRENT_TIMESTAMP
                WHERE user_id = :user_id
            """, {"user_id": user_id, **profile})
    except sqlite3.IntegrityError as err:
        if "profiles.email" in str(err):
            raise AccountError("That email address already belongs to another account.") from err
        raise


def check_credentials(username, password, confirm):
    if not USERNAME_PATTERN.fullmatch(username):
        raise AccountError("Usernames are 3 to 30 characters: letters, numbers and underscores only.")
    if len(password) < MIN_PASSWORD_LENGTH:
        raise AccountError(f"Passwords need at least {MIN_PASSWORD_LENGTH} characters.")
    if password != confirm:
        raise AccountError("The two passwords don't match.")


def clean_profile(raw):
    first_name = raw["first_name"].strip()
    last_name = raw["last_name"].strip()
    if not first_name or not last_name:
        raise AccountError("First and last name are both required.")

    email = raw["email"].strip().lower()
    if not EMAIL_PATTERN.fullmatch(email):
        raise AccountError("That email address doesn't look right.")

    try:
        birthday = datetime.strptime(raw["date_of_birth"].strip(), "%Y-%m-%d").date()
    except ValueError:
        raise AccountError("Date of birth must be a real date written YYYY-MM-DD, like 2001-03-14.")
    today = date.today()
    if birthday > today or birthday.year < today.year - 120:
        raise AccountError("Date of birth must be in the past 120 years.")

    try:
        feet = int(raw["height_feet"])
        inches = int(raw["height_inches"])
    except ValueError:
        raise AccountError("Height needs whole numbers for both feet and inches.")
    height = feet * 12 + inches
    if not 0 <= inches <= 11 or not 36 <= height <= 96:
        raise AccountError("Height must be between 3 ft 0 in and 8 ft 0 in, with 0 to 11 inches.")

    try:
        income = int(raw["annual_income"].replace(",", "").replace("$", "").strip())
    except ValueError:
        raise AccountError("Annual income must be a whole dollar amount, like 55000.")
    if income < 0:
        raise AccountError("Annual income can't be negative.")

    if raw["state"] not in STATES:
        raise AccountError("Pick your state from the list.")
    if raw["smoker"] not in ("Yes", "No"):
        raise AccountError("Answer whether you smoke.")
    if raw["favorite_class"] not in CLASSES:
        raise AccountError("Pick your favorite class from the list.")

    try:
        hours = int(raw["hours_played"].replace(",", "").strip())
    except ValueError:
        raise AccountError("Hours played must be a whole number.")
    if hours < 0:
        raise AccountError("Hours played can't be negative.")

    return {
        "first_name": first_name,
        "last_name": last_name,
        "email": email,
        "date_of_birth": birthday.isoformat(),
        "height_inches": height,
        "annual_income": income,
        "state": raw["state"],
        "smoker": 1 if raw["smoker"] == "Yes" else 0,
        "favorite_class": raw["favorite_class"],
        "hours_played": hours,
    }


class ProfileForm:
    def __init__(self, parent):
        self.frame = ttk.LabelFrame(parent, text="Player survey", padding=12)
        self.vars = {name: tk.StringVar() for name in (
            "first_name", "last_name", "email", "date_of_birth", "height_feet", "height_inches",
            "annual_income", "state", "smoker", "favorite_class", "hours_played",
        )}
        self.inputs = []

        self.add_entry(0, "First name:", "first_name", 24)
        self.add_entry(1, "Last name:", "last_name", 24)
        self.add_entry(2, "Email:", "email", 32)
        self.add_entry(3, "Date of birth:", "date_of_birth", 12, hint="YYYY-MM-DD")

        row = self.add_row(4, "Height:")
        feet = ttk.Spinbox(row, from_=3, to=8, width=4, textvariable=self.vars["height_feet"])
        feet.pack(side="left")
        ttk.Label(row, text="ft").pack(side="left", padx=(4, 12))
        inches = ttk.Spinbox(row, from_=0, to=11, width=4, textvariable=self.vars["height_inches"])
        inches.pack(side="left")
        ttk.Label(row, text="in").pack(side="left", padx=4)
        self.inputs += [(feet, "normal", "disabled"), (inches, "normal", "disabled")]

        self.add_entry(5, "Annual income ($):", "annual_income", 14, hint="whole dollars")

        row = self.add_row(6, "State:")
        state = ttk.Combobox(row, values=STATES, width=5, textvariable=self.vars["state"])
        state.pack(side="left")
        self.inputs.append((state, "readonly", "disabled"))

        row = self.add_row(7, "Do you smoke?")
        for answer in ("Yes", "No"):
            button = ttk.Radiobutton(row, text=answer, value=answer, variable=self.vars["smoker"])
            button.pack(side="left", padx=(0, 12))
            self.inputs.append((button, "normal", "disabled"))

        row = self.add_row(8, "Favorite class:")
        favorite = ttk.Combobox(row, values=CLASSES, width=10, textvariable=self.vars["favorite_class"])
        favorite.pack(side="left")
        self.inputs.append((favorite, "readonly", "disabled"))

        self.add_entry(9, "Hours played:", "hours_played", 10)

    def add_row(self, row, label):
        ttk.Label(self.frame, text=label).grid(row=row, column=0, sticky="w", pady=3, padx=(0, 10))
        holder = ttk.Frame(self.frame)
        holder.grid(row=row, column=1, sticky="w", pady=3)
        return holder

    def add_entry(self, row, label, name, width, hint=""):
        holder = self.add_row(row, label)
        entry = ttk.Entry(holder, textvariable=self.vars[name], width=width)
        entry.pack(side="left")
        if hint:
            ttk.Label(holder, text=hint, foreground="grey").pack(side="left", padx=6)
        self.inputs.append((entry, "normal", "readonly"))

    def fill(self, profile):
        feet, inches = divmod(profile["height_inches"], 12)
        values = {
            "first_name": profile["first_name"],
            "last_name": profile["last_name"],
            "email": profile["email"],
            "date_of_birth": profile["date_of_birth"],
            "height_feet": feet,
            "height_inches": inches,
            "annual_income": f"{profile['annual_income']:,}",
            "state": profile["state"],
            "smoker": "Yes" if profile["smoker"] else "No",
            "favorite_class": profile["favorite_class"],
            "hours_played": profile["hours_played"],
        }
        for name, value in values.items():
            self.vars[name].set(value)

    def answers(self):
        return {name: var.get() for name, var in self.vars.items()}

    def set_editable(self, editable):
        for widget, on_state, off_state in self.inputs:
            widget.configure(state=on_state if editable else off_state)


class AccountApp:
    def __init__(self, root, conn):
        self.root = root
        self.conn = conn
        self.user_id = None
        self.screen = None
        root.title("Player Accounts")
        root.minsize(540, 320)
        self.show_sign_in()

    def new_screen(self, title, subtitle):
        if self.screen is not None:
            self.screen.destroy()
        self.root.unbind("<Return>")

        self.screen = ttk.Frame(self.root, padding=16)
        self.screen.pack(fill="both", expand=True)
        ttk.Label(self.screen, text=title, font=("Helvetica", 18, "bold")).grid(
            row=0, column=0, sticky="w")
        ttk.Label(self.screen, text=subtitle, wraplength=500, justify="left").grid(
            row=1, column=0, sticky="w", pady=(2, 12))

        self.status_var = tk.StringVar()
        self.status_label = tk.Label(self.screen, textvariable=self.status_var,
                                     wraplength=500, justify="left", anchor="w")
        self.status_label.grid(row=99, column=0, sticky="ew", pady=(10, 0))
        return self.screen

    def set_status(self, text, ok=False):
        self.status_label.config(fg="dark green" if ok else "red")
        self.status_var.set(text)

    def button_row(self, row):
        holder = ttk.Frame(self.screen)
        holder.grid(row=row, column=0, sticky="w", pady=(12, 0))
        return holder

    def show_sign_in(self, message=""):
        screen = self.new_screen(
            "Sign in", "Sign in to see and update your survey answers, or create a new account.")

        box = ttk.Frame(screen)
        box.grid(row=2, column=0, sticky="w")
        username_var = tk.StringVar()
        password_var = tk.StringVar()
        ttk.Label(box, text="Username:").grid(row=0, column=0, sticky="w", pady=3)
        username_entry = ttk.Entry(box, textvariable=username_var, width=28)
        username_entry.grid(row=0, column=1, sticky="w", pady=3, padx=(10, 0))
        ttk.Label(box, text="Password:").grid(row=1, column=0, sticky="w", pady=3)
        ttk.Entry(box, textvariable=password_var, width=28, show="•").grid(
            row=1, column=1, sticky="w", pady=3, padx=(10, 0))

        def submit():
            username = username_var.get().strip()
            if not username or not password_var.get():
                self.set_status("Enter your username and password.")
                return
            user_id = sign_in(self.conn, username, password_var.get())
            if user_id is None:
                password_var.set("")
                self.set_status("Wrong username or password.")
                return
            self.user_id = user_id
            self.show_profile()

        buttons = self.button_row(3)
        ttk.Button(buttons, text="Sign in", command=submit).pack(side="left")
        ttk.Button(buttons, text="Create an account",
                   command=self.show_create_account).pack(side="left", padx=8)

        self.root.bind("<Return>", lambda event: submit())
        if message:
            self.set_status(message, ok=True)
        username_entry.focus()

    def show_create_account(self):
        screen = self.new_screen(
            "Create an account",
            "Choose a username and password, then answer every survey question. "
            "You can change your answers any time after signing in.")

        login = ttk.LabelFrame(screen, text="Login", padding=12)
        login.grid(row=2, column=0, sticky="ew")
        username_var = tk.StringVar()
        password_var = tk.StringVar()
        confirm_var = tk.StringVar()
        fields = [
            ("Username:", username_var, "", "letters, numbers, _"),
            ("Password:", password_var, "•", f"at least {MIN_PASSWORD_LENGTH} characters"),
            ("Confirm password:", confirm_var, "•", ""),
        ]
        for row, (label, var, show, hint) in enumerate(fields):
            ttk.Label(login, text=label).grid(row=row, column=0, sticky="w", pady=3, padx=(0, 10))
            entry = ttk.Entry(login, textvariable=var, width=24, show=show)
            entry.grid(row=row, column=1, sticky="w", pady=3)
            ttk.Label(login, text=hint, foreground="grey").grid(row=row, column=2, sticky="w", padx=6)
            if row == 0:
                entry.focus()

        form = ProfileForm(screen)
        form.frame.grid(row=3, column=0, sticky="ew", pady=(10, 0))

        def submit():
            username = username_var.get().strip()
            try:
                check_credentials(username, password_var.get(), confirm_var.get())
                profile = clean_profile(form.answers())
                self.user_id = create_account(self.conn, username, password_var.get(), profile)
            except AccountError as err:
                self.set_status(str(err))
                return
            self.show_profile("Account created. These are the answers we saved.")

        buttons = self.button_row(4)
        ttk.Button(buttons, text="Create account", command=submit).pack(side="left")
        ttk.Button(buttons, text="Back to sign in", command=self.show_sign_in).pack(side="left", padx=8)
        self.root.bind("<Return>", lambda event: submit())

    def show_profile(self, message=""):
        profile = get_profile(self.conn, self.user_id)
        screen = self.new_screen(
            f"Welcome, {profile['first_name']}",
            f"Signed in as {profile['username']}. These are your answers on file, "
            f"last updated {profile['updated_at']} UTC. Press Edit answers to change them.")

        form = ProfileForm(screen)
        form.frame.grid(row=2, column=0, sticky="ew")
        form.fill(profile)
        form.set_editable(False)

        def edit():
            form.set_editable(True)
            edit_button.pack_forget()
            sign_out_button.pack_forget()
            save_button.pack(side="left")
            cancel_button.pack(side="left", padx=8)
            self.root.bind("<Return>", lambda event: save())
            self.set_status("")

        def save():
            try:
                update_profile(self.conn, self.user_id, clean_profile(form.answers()))
            except AccountError as err:
                self.set_status(str(err))
                return
            self.show_profile("Your answers were saved.")

        def sign_out():
            self.user_id = None
            self.show_sign_in("You have been signed out.")

        buttons = self.button_row(3)
        edit_button = ttk.Button(buttons, text="Edit answers", command=edit)
        sign_out_button = ttk.Button(buttons, text="Sign out", command=sign_out)
        save_button = ttk.Button(buttons, text="Save changes", command=save)
        cancel_button = ttk.Button(buttons, text="Cancel", command=self.show_profile)
        edit_button.pack(side="left")
        sign_out_button.pack(side="left", padx=8)

        if message:
            self.set_status(message, ok=True)


def main():
    conn = initialize_database()
    print(f"sqlite3 version: {sqlite3.sqlite_version}")
    print(f"using database {DB_PATH}")

    root = tk.Tk()
    AccountApp(root, conn)

    def on_close():
        conn.close()
        root.destroy()

    root.protocol("WM_DELETE_WINDOW", on_close)
    root.mainloop()


if __name__ == "__main__":
    main()
