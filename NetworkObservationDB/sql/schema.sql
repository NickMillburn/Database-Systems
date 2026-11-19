-- Network Observation Database - schema for MySQL / MariaDB
-- Same five tables as the UML + schema deliverable, with the PostgreSQL types
-- swapped for MySQL ones:
--   MACADDR     -> CHAR(17)      'aa:bb:cc:dd:ee:ff'
--   INET        -> VARCHAR(45)   long enough for IPv6 text
--   TIMESTAMPTZ -> DATETIME      always stored in UTC
--
-- Running this file DROPS and recreates the tables.

CREATE DATABASE IF NOT EXISTS netobs;
USE netobs;

-- children first so the foreign keys do not block the drops
DROP TABLE IF EXISTS connection;
DROP TABLE IF EXISTS service;
DROP TABLE IF EXISTS interface;
DROP TABLE IF EXISTS organization;
DROP TABLE IF EXISTS device;

-- A machine on the network.
CREATE TABLE device (
    device_id     INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(60) NOT NULL UNIQUE,
    device_type   VARCHAR(20) NOT NULL,      -- desktop, laptop, phone, server, unknown, ...
    owner         VARCHAR(40),
    registered_on DATE NOT NULL
) ENGINE=InnoDB;

-- One way a device connects (Ethernet, Wi-Fi, Tailscale). HAS: device 1 : N interface.
CREATE TABLE interface (
    mac_address CHAR(17) PRIMARY KEY,
    device_id   INT NOT NULL,
    if_type     VARCHAR(20) NOT NULL,        -- ethernet, wifi, tailscale
    FOREIGN KEY (device_id) REFERENCES device(device_id)
) ENGINE=InnoDB;

-- Something a device listens for. Weak entity: port 8096 means nothing without
-- its device, so the device's key is part of the primary key. RUNS: device 1 : N service.
CREATE TABLE service (
    device_id    INT NOT NULL,
    port         INT NOT NULL,
    protocol     VARCHAR(4) NOT NULL,        -- tcp or udp
    service_name VARCHAR(60) NOT NULL,
    PRIMARY KEY (device_id, port, protocol),
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
    FOREIGN KEY (mac_address) REFERENCES interface(mac_address),
    FOREIGN KEY (org_id) REFERENCES organization(org_id),
    FOREIGN KEY (svc_device_id, svc_port, svc_protocol)
        REFERENCES service(device_id, port, protocol),
    -- the reports filter by time, so index it on its own and together with the interface
    INDEX idx_conn_started (started_at),
    INDEX idx_conn_mac_started (mac_address, started_at),
    -- the Log a connection page looks rows up by address
    INDEX idx_conn_remote_ip (remote_ip),
    INDEX idx_conn_local_ip (local_ip)
) ENGINE=InnoDB;
