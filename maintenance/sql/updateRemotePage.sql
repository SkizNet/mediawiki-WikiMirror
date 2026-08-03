-- This file is intended to be run as part of the WikiMirror:UpdateRemotePage maintenance script
-- It should *NOT* be run standalone!

CREATE INDEX rp_touched ON /*_*/wikimirror_page(rp_touched);

RENAME TABLE
    /*_*/remote_page TO /*_*/remote_page_old,
    /*_*/wikimirror_page TO /*_*/remote_page;

DROP TABLE /*_*/remote_page_old;

RENAME TABLE
    /*_*/remote_redirect TO /*_*/remote_redirect_old,
    /*_*/wikimirror_redirect TO /*_*/remote_redirect;

DROP TABLE /*_*/remote_redirect_old;
