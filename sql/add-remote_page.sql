CREATE TABLE /*_*/remote_page (
    -- Remote ID for this page
    rp_id int unsigned NOT NULL PRIMARY KEY,
    -- Remote namespace ID for this page
    rp_namespace int NOT NULL,
    -- DB key for the page (without namespace prefix)
    rp_title varbinary(255) NOT NULL,
    -- When this record was last updated from the remote wiki (timestamp)
    rp_updated binary(14) NOT NULL,
    UNIQUE KEY rp_ns_title (rp_namespace, rp_title)
) /*$wgDBTableOptions*/;
