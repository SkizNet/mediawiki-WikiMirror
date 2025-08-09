-- This file is intended to be run as part of the WikiMirror:UpdateRemotePage maintenance script
-- It should *NOT* be run standalone!

DROP TABLE /*_*/wikimirror_page;

DROP TABLE /*_*/wikimirror_redirect;

RENAME TABLE
    /*_*/remote_page TO /*_*/remote_page_old,
    /*_*/remote_page2 TO /*_*/remote_page;

DROP TABLE /*_*/remote_page_old;

RENAME TABLE
    /*_*/remote_redirect TO /*_*/remote_redirect_old,
    /*_*/remote_redirect2 TO /*_*/remote_redirect;

DROP TABLE /*_*/remote_redirect_old;
