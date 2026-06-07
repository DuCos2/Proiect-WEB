ALTER TABLE locations MODIFY latitude DECIMAL(10, 6);
ALTER TABLE locations MODIFY longitude DECIMAL(10, 6);

SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO cities (id, name, county) VALUES (1, 'Iasi', 'Iasi')
    ON DUPLICATE KEY UPDATE name=name;

INSERT INTO sports (id, name) VALUES
                                  (1, 'Football'),
                                  (2, 'Basketball'),
                                  (3, 'Tennis'),
                                  (4, 'Volleyball'),
                                  (5, 'Running'),
                                  (6, 'Cycling'),
                                  (7, 'Badminton'),
                                  (8, 'Table Tennis')
    ON DUPLICATE KEY UPDATE name=name;

DELETE FROM location_sports;
DELETE FROM locations;

INSERT INTO locations (id, city_id, name, area, latitude, longitude, address) VALUES
                                                                                  (1, 1, 'Copou Arena', 'Copou', 47.187800, 27.568500, 'Aleea Grigore Ghica Vodă, Iași'),
                                                                                  (2, 1, 'Palas Sport Court', 'Palas', 47.156500, 27.587500, 'Strada Palas, Iași'),
                                                                                  (3, 1, 'Ciric Tennis Club', 'Ciric', 47.178200, 27.618600, 'Zona de Agrement Ciric, Iași'),
                                                                                  (4, 1, 'Parc Podu Ros', 'Podu Ros', 47.150900, 27.584300, 'Bulevardul Socola, Iași'),
                                                                                  (5, 1, 'Teren Pacurari', 'Pacurari', 47.172200, 27.550100, 'Strada Păcurari, Iași'),
                                                                                  (6, 1, 'Centru Fit & Play', 'Centru', 47.159500, 27.581500, 'Bulevardul Ștefan cel Mare și Sfânt, Iași');

INSERT INTO location_sports (location_id, sport_id) VALUES
                                                        (1, 1), (1, 3),         -- Copou Arena: Football, Tennis
                                                        (2, 2), (2, 4),         -- Palas Sport Court: Basketball, Volleyball
                                                        (3, 3), (3, 7), (3, 8), -- Ciric Tennis Club: Tennis, Badminton, Table Tennis
                                                        (4, 5), (4, 6),         -- Parc Podu Ros: Running, Cycling
                                                        (5, 4), (5, 2),         -- Pacurari Gymnastics: Volleyball, Basketball
                                                        (6, 8), (6, 7);         -- Centru Fit & Play: Table Tennis, Badminton

SET FOREIGN_KEY_CHECKS = 1;