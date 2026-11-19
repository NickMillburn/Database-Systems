-- ============================================================================
-- schema.sql
--
-- WHAT THIS FILE DOES
-- Creates the netobs database and its five empty tables. This is the step that
-- turns the schema from the design deliverable into a real database.
-- Run it with:  mysql < sql/schema.sql   (deploy/install.sh does this)
--
-- HOW THE ER DESIGN BECAME TABLES
--   Each entity became a table and each attribute became a column.
--   Each 1:N relationship became a foreign key column on the "many" side:
--     HAS        device 1:N interface         interface.device_id
--     RUNS       device 1:N service           service.device_id
--     CONNECTS   interface 1:N connection     connection.mac_address
--     BELONGS TO organization 1:N connection  connection.org_id          (optional)
--     TARGETS    service 1:N connection       connection.svc_* (3 cols)  (optional)
--   A required relationship is a NOT NULL foreign key. An optional one allows NULL.
--
-- ORDER MATTERS
--   A foreign key can only point at a table that already exists, so parents are
--   created before children: device, interface, service, organization, connection.
--   Dropping goes the other way round.
--
-- CHANGES FROM THE DELIVERABLE
-- Same five tables as the UML + schema deliverable, with the PostgreSQL types
-- swapped for MySQL ones:
--   MACADDR     -> CHAR(17)      'aa:bb:cc:dd:ee:ff'
--   INET        -> VARCHAR(45)   long enough for IPv6 text
--   TIMESTAMPTZ -> DATETIME      always stored in UTC
--   VARCHAR     -> ENUM          for direction and access_path, so only listed values are accepted
--
-- Running this file DROPS and recreates the tables.
-- ============================================================================

-- ---- Section 1: the database itself ----
-- USE makes every statement below apply to netobs.
CREATE DATABASE IF NOT EXISTS netobs;
USE netobs;

-- ---- Section 2: remove old copies of the tables ----
-- children first so the foreign keys do not block the drops
DROP TABLE IF EXISTS connection;
DROP TABLE IF EXISTS service;
DROP TABLE IF EXISTS interface;
DROP TABLE IF EXISTS organization;
DROP TABLE IF EXISTS device;

-- ---- Section 3: the tables, parents first ----
-- ENGINE=InnoDB is the MySQL storage engine that enforces foreign keys and supports transactions.
--
-- A machine on the network.
CREATE TABLE device (
    -- surrogate key: MySQL hands out 1, 2, 3 ... on its own
    device_id     INT AUTO_INCREMENT PRIMARY KEY,
    -- UNIQUE: no two devices may share a name
    name          VARCHAR(60) NOT NULL UNIQUE,
    device_type   VARCHAR(20) NOT NULL,      -- desktop, laptop, phone, server, unknown, ...
    owner         VARCHAR(40),
    registered_on DATE NOT NULL
) ENGINE=InnoDB;

-- One way a device connects (Ethernet, Wi-Fi, Tailscale). HAS: device 1 : N interface.
CREATE TABLE interface (
    -- natural key: a MAC address is already unique, so no id column is needed
    mac_address CHAR(17) PRIMARY KEY,
    device_id   INT NOT NULL,
    if_type     VARCHAR(20) NOT NULL,        -- ethernet, wifi, tailscale
    -- HAS: every interface must belong to an existing device
    FOREIGN KEY (device_id) REFERENCES device(device_id)
) ENGINE=InnoDB;

-- Something a device listens for. Weak entity: port 8096 means nothing without
-- its device, so the device's key is part of the primary key. RUNS: device 1 : N service.
CREATE TABLE service (
    device_id    INT NOT NULL,
    port         INT NOT NULL,
    protocol     VARCHAR(4) NOT NULL,        -- tcp or udp
    service_name VARCHAR(60) NOT NULL,
    -- composite primary key: the three columns together identify one service
    PRIMARY KEY (device_id, port, protocol),
    -- RUNS: every service must belong to an existing device
    FOREIGN KEY (device_id) REFERENCES device(device_id)
) ENGINE=InnoDB;

-- Who owns an outside address (Google, Cloudflare, an ISP).
CREATE TABLE organization (
    org_id   INT AUTO_INCREMENT PRIMARY KEY,
    org_name VARCHAR(100) NOT NULL UNIQUE,
    country  CHAR(2),
    asn      INT
) ENGINE=InnoDB;

-- One observed connection, seen from one of my interfaces.
--   local_*  = my interface's end        remote_* = the other end
--   bytes_sent / bytes_received are from my interface's point of view
--   CONNECTS:   mac_address  (required)  which interface saw it
--   BELONGS TO: org_id       (nullable)  who owns the remote address
--   TARGETS:    svc_*        (nullable)  the registered service that was hit
CREATE TABLE connection (
    connection_id  BIGINT AUTO_INCREMENT PRIMARY KEY,
    mac_address    CHAR(17) NOT NULL,
    org_id         INT NULL,
    svc_device_id  INT NULL,
    svc_port       INT NULL,
    svc_protocol   VARCHAR(4) NULL,
    local_ip       VARCHAR(45) NOT NULL,
    local_port     INT NOT NULL,
    remote_ip      VARCHAR(45) NOT NULL,
    remote_port    INT NOT NULL,
    protocol       VARCHAR(4) NOT NULL,
    started_at     DATETIME NOT NULL,
    ended_at       DATETIME NOT NULL,
    bytes_sent     BIGINT NOT NULL DEFAULT 0,
    bytes_received BIGINT NOT NULL DEFAULT 0,
    direction      ENUM('inbound', 'outbound', 'internal') NOT NULL,
    access_path    ENUM('lan', 'internet', 'tailscale', 'cloudflare_tunnel', 'port_forward') NOT NULL,
    source         VARCHAR(20) NOT NULL,     -- generator, simulator, manual
    -- CONNECTS: required (mac_address is NOT NULL above)
    FOREIGN KEY (mac_address) REFERENCES interface(mac_address),
    -- BELONGS TO: optional (org_id may be NULL)
    FOREIGN KEY (org_id) REFERENCES organization(org_id),
    -- TARGETS: optional, and three columns wide because it points at service's three-column key
    FOREIGN KEY (svc_device_id, svc_port, svc_protocol)
        REFERENCES service(device_id, port, protocol),
    -- the reports filter by time, so index it on its own and together with the interface
    INDEX idx_conn_started (started_at),
    INDEX idx_conn_mac_started (mac_address, started_at),
    -- the Log a connection page looks rows up by address
    INDEX idx_conn_remote_ip (remote_ip),
    INDEX idx_conn_local_ip (local_ip)
) ENGINE=InnoDB;
