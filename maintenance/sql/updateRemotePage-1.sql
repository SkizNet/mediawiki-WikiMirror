-- This file is intended to be run as part of the WikiMirror:UpdateRemotePage maintenance script
-- It should *NOT* be run standalone!

DROP TABLE IF EXISTS /*_*/remote_page2;
DROP TABLE IF EXISTS /*_*/remote_redirect2;

CREATE TABLE /*_*/remote_page2 (
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

CREATE TABLE /*_*/remote_redirect2 (
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
