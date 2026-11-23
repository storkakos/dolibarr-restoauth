-- restoauth: authorization codes (short-lived, single use, stored as sha256 only)
CREATE TABLE llx_restoauth_code (
  rowid           integer AUTO_INCREMENT PRIMARY KEY,
  entity          integer DEFAULT 1 NOT NULL,
  code_hash       varchar(64) NOT NULL,
  fk_client       integer NOT NULL,
  fk_user         integer NOT NULL,
  redirect_uri    text NOT NULL,
  code_challenge  varchar(128) NOT NULL,         -- PKCE S256
  resource        varchar(255) NULL,             -- RFC 8707
  scope           varchar(255) NULL,
  expire_at       datetime NOT NULL,
  datec           datetime NULL
) ENGINE=innodb;
