
ALTER TABLE echoareas ADD IF NOT EXISTS domain VARCHAR(50);
ALTER TABLE echoareas DROP CONSTRAINT echoareas_tag_key;
ALTER TABLE echoareas ADD CONSTRAINT echoareas_tag_key UNIQUE(tag,domain);
UPDATE echoareas SET domain='fidonet' WHERE domain IS NULL;
CREATE INDEX echoareas_domain_idx ON echoareas(domain);

ALTER TABLE nodelist ADD domain VARCHAR(50);
CREATE INDEX nodelist_domain_idx ON nodelist(domain);

ALTER TABLE nodelist_metadata ADD domain VARCHAR(50);
CREATE INDEX nodelist_meta_domain_idx ON nodelist_metadata(domain);