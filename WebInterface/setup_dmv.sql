-- Only for local testing. The instructor's server already has this database.
CREATE DATABASE IF NOT EXISTS DMV;
USE DMV;

CREATE TABLE IF NOT EXISTS Persons (
    License CHAR(9) NOT NULL,
    State   CHAR(2) NOT NULL,
    Last    VARCHAR(50),
    First   VARCHAR(50),
    Phone   CHAR(10),
    Email   VARCHAR(100),
    PRIMARY KEY (License, State)
);

CREATE TABLE IF NOT EXISTS Cars (
    VIN    CHAR(17) NOT NULL PRIMARY KEY,
    Make   VARCHAR(50),
    Model  VARCHAR(50),
    Year   INT,
    Weight INT
);

CREATE TABLE IF NOT EXISTS Title (
    TitleID  INT AUTO_INCREMENT PRIMARY KEY,
    DL       CHAR(9) NOT NULL,
    DL_State CHAR(2) NOT NULL,
    VIN      CHAR(17) NOT NULL,
    State    CHAR(2),
    Month    INT CHECK (Month BETWEEN 1 AND 12),
    Year     INT,
    FOREIGN KEY (DL, DL_State) REFERENCES Persons(License, State),
    FOREIGN KEY (VIN) REFERENCES Cars(VIN)
);
