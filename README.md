SearchController is from a Zend application. The usernames and passwords were changed for the database access. The Zend 
Framework is 1.11 and it is hosted on Red Had Enterprise 5.6.

It is the dominant controller for the application, and uses data from authentication and admin controllers 
and from a MySQL 5 database and a Solr java application via a PHP model. It can also use a PHP Lucene model, 
but that was unacceptibly slow.
