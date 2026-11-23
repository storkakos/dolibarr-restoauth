-- restoauth: refresh tokens (sha256 only). One family = one connection (client + user).
-- The matching access token lives in llx_oauth_token with service 'dolibarr_rest_api' and state 'restoauth:<family_id>'.
CREATE TABLE llx_restoauth_refresh (
  rowid           integer AUTO_INCREMENT PRIMARY KEY,
  entity          integer DEFAULT 1 NOT NULL,
  token_hash      varchar(64) NOT NULL,
  family_id       varchar(32) NOT NULL,
  fk_client       integer NOT NULL,
  fk_user         integer NOT NULL,
  resource        varchar(255) NULL,
  scope           varchar(255) NULL,
  family_datec    datetime NOT NULL,             -- start of the connection (consent)
  expire_at       datetime NOT NULL,             -- idle expiry, capped by family_datec + max days
  used_at         datetime NULL,                 -- set when rotated; presenting it again = reuse -> family revoked
  revoked         smallint DEFAULT 0 NOT NULL,
  datec           datetime NULL
) ENGINE=innodb;
