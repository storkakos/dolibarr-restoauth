-- restoauth: OAuth clients (dynamic client registration, RFC 7591)
CREATE TABLE llx_restoauth_client (
  rowid           integer AUTO_INCREMENT PRIMARY KEY,
  entity          integer DEFAULT 1 NOT NULL,
  client_id       varchar(64) NOT NULL,
  secret_hash     varchar(64) NULL,              -- sha256 of client_secret; NULL for public clients (auth method none)
  client_name     varchar(255) NULL,
  redirect_uris   text NOT NULL,                 -- JSON array
  auth_method     varchar(32) DEFAULT 'none' NOT NULL,
  datec           datetime NULL,
  tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
