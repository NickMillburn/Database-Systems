import sqlite3

#if we don't have a database and we want to create one, here is some sample code

#if the database doesn't exist, it will be made.
#you could create the database in a different directory and specifying its path
connection = sqlite3.connect('dbdemo.db')

#we need to connect to the database
cursor = connection.cursor()

#initially this is an empty database, and we'll need to construct a table and put some data in it

#first, create a table
cursor.execute("CREATE TABLE IF NOT EXISTS users( id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT unique, age INTEGER) ")

#now we can put some data into the table
cursor.execute("INSERT INTO users (id, name, email, age) VALUES (10, 'Yibbie Yang', 'yibbie-yang@uiowa.edu', 40)")

#commit the change to the database
connection.commit()

#now we can execute a simple query
sql = 'SELECT * from users'
cursor.execute(sql)
print(cursor.fetchall())

#there are other ways to execute a query and then work with the data produced
results = cursor.fetchall()
for result in results:
    print(result)


#make sure you close your connection to the database once you are finished.
connection.close()

#here is how to create a database from a csv file using pandas

import sqlite3
import pandas as pd

# 1. Load the CSV file into a DataFrame
df = pd.read_csv("your_file.csv")

# 2. Connect to SQLite (creates the database file if it doesn't exist)
conn = sqlite3.connect("my_database.db")

# 3. Write the data to a SQL table
# Set index=False so the DataFrame row numbers aren't saved as a column
df.to_sql("my_table", conn, if_exists="replace", index=False)

# 4. Close the connection
conn.close()