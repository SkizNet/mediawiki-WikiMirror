CREATE TABLE /*_*/remote_redirect (
    -- Remote page ID this redirect is from
    rr_from int unsigned NOT NULL PRIMARY KEY,
    -- Namespace ID of redirect destination
    rr_namespace int NOT NULL,
    -- DB key of redirect destination (without namespace prefix)
    rr_title varbinary(255) NOT NULL,
    -- When this record was last updated from the remote wiki (timestamp)
    rr_updated binary(14) NOT NULL,
    INDEX rr_ns_title (rr_namespace, rr_title)
) /*$wgDBTableOptions*/;
