"""
Assignment 2: Building a database from my CSV and querying it

This takes the Terraria weapon CSV I submitted for Assignment 1, loads it into a
SQLite database, and runs a set of queries against it.

The data is 395 weapons from Terraria 1.4.4.9. Each row is one weapon, with its
measured DPS, its combat stats, and where it sits in the game's boss progression.

To run it:
    python terraria_db.py

Needs pandas and sqlite3. sqlite3 comes with Python. The CSV needs to be in the
same folder as this script.
"""

import os
import sqlite3
import pandas as pd

HERE = os.path.dirname(os.path.abspath(__file__))
CSV_PATH = os.path.join(HERE, "terraria_weapons.csv")
DB_PATH = os.path.join(HERE, "terraria.db")


def show(cursor, sql, title):
    """Run a query and print the results in lined up columns."""
    print("\n" + "=" * 78)
    print(title)
    print("=" * 78)

    cursor.execute(sql)
    rows = cursor.fetchall()
    headers = [d[0] for d in cursor.description]

    widths = [
        max(len(str(h)), *(len(str(r[i])) for r in rows)) if rows else len(str(h))
        for i, h in enumerate(headers)
    ]

    print("  ".join(str(h).ljust(w) for h, w in zip(headers, widths)))
    print("  ".join("-" * w for w in widths))
    for row in rows:
        print("  ".join(str(v).ljust(w) for v, w in zip(row, widths)))
    print(f"\n({len(rows)} rows)")


# Step 1: read the csv
print(f"sqlite3 version: {sqlite3.sqlite_version}")
print(f"reading {CSV_PATH}")

df = pd.read_csv(CSV_PATH)
print(f"loaded {len(df)} rows and {len(df.columns)} columns from the csv")

# Step 2: create the database and the table, starting fresh so rows do not stack up
if os.path.exists(DB_PATH):
    os.remove(DB_PATH)

connection = sqlite3.connect(DB_PATH)
cursor = connection.cursor()

# Writing the schema by hand so the types, the primary key and the nullable columns are mine, not pandas' guesses.
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

# Step 3: append into the table I just defined so pandas keeps my schema
df.to_sql("weapons", connection, if_exists="append", index=False)
connection.commit()

cursor.execute("SELECT COUNT(*) FROM weapons")
count = cursor.fetchone()[0]
print(f"inserted {count} rows into the weapons table")
if count != len(df):
    raise SystemExit(f"row count mismatch: csv had {len(df)}, database has {count}")

# Queries

# Groups every weapon class into pre Hardmode and Hardmode (rank 7 is the Wall of Flesh) to see how much each class gains.
show(cursor, """
    SELECT
        damage_class,
        CASE WHEN progression_rank >= 7 THEN 'Hardmode' ELSE 'Pre-Hardmode' END AS game_half,
        COUNT(*)                          AS weapon_count,
        ROUND(AVG(dps_single_target), 1)  AS avg_dps,
        MIN(dps_single_target)            AS weakest,
        MAX(dps_single_target)            AS strongest
    FROM weapons
    GROUP BY damage_class, game_half
    ORDER BY damage_class, game_half DESC
""", "QUERY 1: Average DPS by class, before and after Hardmode")

# Correlated subquery that keeps a row only if it ties the best DPS in its own tier, so I get the weapon name and not just the number.
show(cursor, """
    SELECT
        w.progression_rank,
        w.progression_tier,
        w.weapon_name,
        w.damage_class,
        w.dps_single_target
    FROM weapons AS w
    WHERE w.dps_single_target = (
        SELECT MAX(inner_w.dps_single_target)
        FROM weapons AS inner_w
        WHERE inner_w.progression_tier = w.progression_tier
    )
    ORDER BY w.progression_rank
""", "QUERY 2: The strongest weapon available at each stage of the game")

# Range query filtering on three things at once: pre Hardmode, DPS between 60 and 200, and autoswing on so it is not painful to hold down.
show(cursor, """
    SELECT
        weapon_name,
        damage_class,
        progression_tier,
        dps_single_target,
        ROUND(attacks_per_second, 2) AS attacks_per_sec
    FROM weapons
    WHERE progression_rank < 7
      AND dps_single_target BETWEEN 60 AND 200
      AND autoswing = 1
    ORDER BY dps_single_target DESC
""", "QUERY 3: Comfortable pre-Hardmode weapons, DPS between 60 and 200, autoswing only")

# Works out the multi target to single target ratio on the fly and skips the 86 weapons with no multi target number.
show(cursor, """
    SELECT
        weapon_name,
        damage_class,
        dps_single_target,
        dps_multi_target,
        ROUND(dps_multi_target / dps_single_target, 2) AS crowd_multiplier
    FROM weapons
    WHERE dps_multi_target IS NOT NULL
    ORDER BY crowd_multiplier DESC
    LIMIT 15
""", "QUERY 4: Top 15 crowd control weapons, by how much they gain against a group")

# Averages crit by rarity tier, with HAVING throwing out any tier holding fewer than 5 weapons since that average would be noise.
show(cursor, """
    SELECT
        rarity_tier,
        COUNT(*)                          AS weapon_count,
        ROUND(AVG(crit_chance_bonus), 2)  AS avg_crit_bonus,
        ROUND(AVG(dps_single_target), 1)  AS avg_dps
    FROM weapons
    GROUP BY rarity_tier
    HAVING COUNT(*) >= 5
    ORDER BY rarity_tier
""", "QUERY 5: Does higher rarity actually mean a higher crit chance?")

# Divides DPS by item value for damage per coin, with NULLIF dodging the zero value weapons and a subquery keeping only above average DPS.
show(cursor, """
    SELECT
        weapon_name,
        damage_class,
        progression_tier,
        dps_single_target,
        item_value_copper,
        ROUND(CAST(dps_single_target AS REAL) / NULLIF(item_value_copper, 0), 4) AS dps_per_copper
    FROM weapons
    WHERE item_value_copper > 0
      AND dps_single_target > (SELECT AVG(dps_single_target) FROM weapons)
    ORDER BY dps_per_copper DESC
    LIMIT 10
""", "QUERY 6: Best damage per coin, among weapons that beat the average DPS")

connection.close()
print("\ndone. database written to", DB_PATH)
