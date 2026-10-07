-- Created once, when the PostgreSQL container first starts: the second
-- database the PHPUnit suite uses, beside the application database that the
-- image creates from POSTGRES_DB.
CREATE DATABASE bnb_testing OWNER bnb_test;
