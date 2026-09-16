import os
import sqlite3
import tkinter as tk
from tkinter import ttk
import pandas as pd
import bcrypt


DB_NAME = "users.db"

def initialize_database():
    if not os.path.exists(DB_NAME):
        conn = sqlite3.connect(DB_NAME)