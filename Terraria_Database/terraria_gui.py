"""
Assignment 3: Tkinter GUI for the Terraria weapon database.

Rebuilds terraria.db from terraria_weapons.csv, then embeds Query 3 from
Assignment 2 (the range selection query) behind a form. Only weapons from the
chosen boss tier show up.

Run with: python3 terraria_gui.py
"""


import os
import sqlite3
import tkinter as tk
from tkinter import ttk
import pandas as pd

HERE = os.path.dirname(os.path.abspath(__file__))
CSV_PATH = os.path.join(HERE, "terraria_weapons.csv")
DB_PATH = os.path.join(HERE, "terraria.db")


# build the database from the csv
print(f"sqlite3 version: {sqlite3.sqlite_version}")
print(f"reading {CSV_PATH}")

df = pd.read_csv(CSV_PATH)
print(f"loaded {len(df)} rows and {len(df.columns)} columns from the csv")

if os.path.exists(DB_PATH):
    os.remove(DB_PATH)

connection = sqlite3.connect(DB_PATH)
cursor = connection.cursor()

cursor.execute("""
    CREATE TABLE IF NOT EXISTS weapons (
        weapon_name          TEXT     PRIMARY KEY,
        damage_class         TEXT     NOT NULL,
        progression_tier     TEXT     NOT NULL,
        progression_rank     INTEGER  NOT NULL,
        dps_single_target    INTEGER  NOT NULL,
        dps_multi_target     REAL,
        base_damage          INTEGER,
        knockback            REAL,
        crit_chance_bonus    INTEGER,
        use_time_ticks       INTEGER,
        use_animation_ticks  INTEGER,
        attacks_per_second   REAL,
        shoot_speed          REAL,
        mana_cost            INTEGER,
        item_value_copper    INTEGER,
        rarity_tier          INTEGER,
        autoswing            INTEGER,
        item_id              INTEGER  UNIQUE,
        measurement_notes    TEXT
    )
""")
connection.commit()

df.to_sql("weapons", connection, if_exists="append", index=False)
connection.commit()

cursor.execute("SELECT COUNT(*) FROM weapons")
count = cursor.fetchone()[0]
print(f"inserted {count} rows into the weapons table")
if count != len(df):
    raise SystemExit(f"row count mismatch: csv had {len(df)}, database has {count}")

# Pulls the boss order out of the table
cursor.execute("""
    SELECT DISTINCT progression_rank, progression_tier
    FROM weapons
    ORDER BY progression_rank
""")
TIERS = cursor.fetchall()
TIER_NAMES = [name for rank, name in TIERS]
RANK_OF = {name: rank for rank, name in TIERS}

# Grabs the lowest and highest DPS in the table
cursor.execute("SELECT MIN(dps_single_target), MAX(dps_single_target) FROM weapons")
lowest_dps, highest_dps = cursor.fetchone()

DEFAULT_TIER = "Pre-Boss"
DEFAULT_MIN = str(lowest_dps)
DEFAULT_MAX = str(highest_dps)
DEFAULT_SORT = "Highest DPS"
CLASSES = ["Melee", "Ranger", "Mage", "Summoner"]
SORTS = {
    "Highest DPS": "dps_single_target DESC",
    "Fastest attacks": "attacks_per_second DESC, dps_single_target DESC",
    "Cheapest": "item_value_copper ASC, dps_single_target DESC",
}


def coins(copper):
    """Turn a raw copper value into the platinum/gold/silver/copper the game shows."""
    plat, rest = divmod(copper, 1_000_000)
    gold, rest = divmod(rest, 10_000)
    silver, copper = divmod(rest, 100)
    parts = []
    if plat:
        parts.append(f"{plat}p")
    if gold:
        parts.append(f"{gold}g")
    if silver:
        parts.append(f"{silver}s")
    if copper or not parts:
        parts.append(f"{copper}c")
    return " ".join(parts)


#the window
root = tk.Tk()
root.title("Terraria Weapon Finder")
root.geometry("980x700")
root.minsize(780, 540)
root.columnconfigure(0, weight=1)

tk.Label(root, text="Terraria Weapon Finder", font=("Helvetica", 20, "bold")).grid(
    row=0, column=0, sticky="w", padx=16, pady=(14, 2))
tk.Label(root, wraplength=920, justify="left",
         text="Pick the boss tier you are at and what you like to fight with, then press Submit. "
              "The table shows only the weapons that unlock in that tier. "
              "Your choices stay put between searches, Reset puts everything back to the defaults.").grid(
    row=1, column=0, sticky="w", padx=16, pady=(0, 10))

form = ttk.LabelFrame(root, text="What are you looking for?", padding=12)
form.grid(row=2, column=0, sticky="ew", padx=16)
form.columnconfigure(1, weight=1)

# Dropdown for the boss tier
tk.Label(form, text="Boss tier:").grid(row=0, column=0, sticky="w", pady=3)
tier_var = tk.StringVar()
tk.OptionMenu(form, tier_var, *TIER_NAMES).grid(row=0, column=1, sticky="w", pady=3)

# Two textfields for the DPS band
tk.Label(form, text="DPS between:").grid(row=1, column=0, sticky="w", pady=3)
dps_row = tk.Frame(form)
dps_row.grid(row=1, column=1, sticky="w", pady=3)
min_var = tk.StringVar()
max_var = tk.StringVar()
min_entry = tk.Entry(dps_row, textvariable=min_var, width=8)
min_entry.pack(side="left")
tk.Label(dps_row, text="and").pack(side="left", padx=6)
tk.Entry(dps_row, textvariable=max_var, width=8).pack(side="left")

# Checkboxes for damage class, any mix is allowed
tk.Label(form, text="Damage class:").grid(row=2, column=0, sticky="w", pady=3)
class_row = tk.Frame(form)
class_row.grid(row=2, column=1, sticky="w", pady=3)
class_vars = {}
for cls in CLASSES:
    class_vars[cls] = tk.IntVar()
    tk.Checkbutton(class_row, text=cls, variable=class_vars[cls]).pack(side="left", padx=(0, 10))

# One more checkbox for autoswing since clicking for every swing gets old fast
autoswing_var = tk.IntVar()
tk.Checkbutton(form, text="Autoswing only (no clicking for every swing)",
               variable=autoswing_var).grid(row=3, column=1, sticky="w", pady=3)

# Radio buttons for sort order, only one can be picked
tk.Label(form, text="Sort by:").grid(row=4, column=0, sticky="w", pady=3)
sort_row = tk.Frame(form)
sort_row.grid(row=4, column=1, sticky="w", pady=3)
sort_var = tk.StringVar()
for label in SORTS:
    tk.Radiobutton(sort_row, text=label, variable=sort_var, value=label).pack(side="left", padx=(0, 10))

# Submit and reset buttons
button_row = tk.Frame(root)
button_row.grid(row=3, column=0, sticky="w", padx=16, pady=10)
submit_button = tk.Button(button_row, text="Submit", width=14, bg="black", fg="white")
submit_button.pack(side="left")
reset_button = tk.Button(button_row, text="Reset", width=14)
reset_button.pack(side="left", padx=8)

# Plain english version of what was searched
summary_var = tk.StringVar()
tk.Label(root, textvariable=summary_var, wraplength=920, justify="left",
         font=("Helvetica", 11, "bold")).grid(row=4, column=0, sticky="w", padx=16)

# Results table with a scrollbar
results = tk.Frame(root)
results.grid(row=5, column=0, sticky="nsew", padx=16, pady=(6, 0))
root.rowconfigure(5, weight=1)
results.columnconfigure(0, weight=1)
results.rowconfigure(0, weight=1)

columns = ("weapon", "class", "dps", "aps", "auto", "value")
tree = ttk.Treeview(results, columns=columns, show="headings")
headings = {
    "weapon": ("Weapon", 260, "w"),
    "class": ("Class", 110, "w"),
    "dps": ("DPS", 70, "e"),
    "aps": ("Attacks/sec", 90, "e"),
    "auto": ("Autoswing", 80, "center"),
    "value": ("Value", 110, "e"),
}
for col, (text, width, anchor) in headings.items():
    tree.heading(col, text=text)
    tree.column(col, width=width, anchor=anchor)
tree.grid(row=0, column=0, sticky="nsew")
scrollbar = ttk.Scrollbar(results, orient="vertical", command=tree.yview)
scrollbar.grid(row=0, column=1, sticky="ns")
tree.configure(yscrollcommand=scrollbar.set)

# Status line for errors and row counts
status_var = tk.StringVar()
status_label = tk.Label(root, textvariable=status_var, anchor="w")
status_label.grid(row=6, column=0, sticky="ew", padx=16, pady=(4, 10))


def reset_widgets():
    """Put every widget back to the defaults and wipe the table."""
    tier_var.set(DEFAULT_TIER)
    min_var.set(DEFAULT_MIN)
    max_var.set(DEFAULT_MAX)
    for var in class_vars.values():
        var.set(1)
    autoswing_var.set(0)
    sort_var.set(DEFAULT_SORT)
    tree.delete(*tree.get_children())
    summary_var.set("")
    status_var.set("")
    min_entry.focus()


def submit_pressed():
    """Read the widgets, build the query from them, run it, and fill the table."""
    tier = tier_var.get()
    sort = sort_var.get()
    autoswing_only = autoswing_var.get() == 1
    classes = [cls for cls in CLASSES if class_vars[cls].get() == 1]

    # Check the textfields before touching DB
    try:
        low = int(min_var.get())
        high = int(max_var.get())
    except ValueError:
        status_label.config(fg="red")
        status_var.set("DPS min and max need to be whole numbers.")
        return
    if low > high:
        status_label.config(fg="red")
        status_var.set("Min DPS has to be less than or equal to max DPS.")
        return
    if not classes:
        status_label.config(fg="red")
        status_var.set("Pick at least one damage class.")
        return

    # Grabs every weapon in the picked tier with DPS in the typed range, only the checked classes, and autoswing only if ticked
    placeholders = ", ".join("?" for _ in classes)
    sql = f"""
        SELECT
            weapon_name,
            damage_class,
            dps_single_target,
            ROUND(attacks_per_second, 2),
            autoswing,
            item_value_copper
        FROM weapons
        WHERE progression_rank = ?
          AND dps_single_target BETWEEN ? AND ?
          AND damage_class IN ({placeholders})
    """
    params = [RANK_OF[tier], low, high, *classes]
    if autoswing_only:
        sql += "      AND autoswing = 1\n"
    sql += f"        ORDER BY {SORTS[sort]}"

    cursor.execute(sql, params)
    rows = cursor.fetchall()

    tree.delete(*tree.get_children())
    for weapon, cls, dps, aps, auto, value in rows:
        tree.insert("", "end", values=(
            weapon, cls, dps, aps, "Yes" if auto else "No", coins(value)))

    class_text = ", ".join(classes) if len(classes) < len(CLASSES) else "any class"
    auto_text = ", autoswing only" if autoswing_only else ""
    if rows:
        summary_var.set(
            f"Showing {class_text} weapons from the {tier} tier, "
            f"DPS {low} to {high}{auto_text}, sorted by {sort}.")
    else:
        summary_var.set(
            f"Nothing matched. Try widening the DPS range, ticking more classes, "
            f"or trying a different tier.")

    status_label.config(fg="dark green")
    status_var.set(f"{len(rows)} weapon{'s' if len(rows) != 1 else ''} found.")


def on_close():
    """Close the database connection before the window goes away."""
    connection.close()
    root.destroy()


submit_button.config(command=submit_pressed)
reset_button.config(command=reset_widgets)
root.bind("<Return>", lambda event: submit_pressed())
root.protocol("WM_DELETE_WINDOW", on_close)

reset_widgets()
root.mainloop()
