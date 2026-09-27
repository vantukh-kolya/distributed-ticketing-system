CREATE USER ticketing WITH PASSWORD 'ticketing';

CREATE DATABASE booking OWNER ticketing;
CREATE DATABASE inventory OWNER ticketing;
CREATE DATABASE payment OWNER ticketing;
CREATE DATABASE orchestrator OWNER ticketing;
