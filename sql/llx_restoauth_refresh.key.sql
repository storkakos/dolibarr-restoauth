ALTER TABLE llx_restoauth_refresh ADD UNIQUE INDEX uk_restoauth_refresh_hash (token_hash);
ALTER TABLE llx_restoauth_refresh ADD INDEX idx_restoauth_refresh_family (family_id);
ALTER TABLE llx_restoauth_refresh ADD INDEX idx_restoauth_refresh_user (fk_user);
