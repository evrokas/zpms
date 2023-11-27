TRUNCATE TABLE `patients`;
INSERT INTO `patients` (guid, cuser, pname, pdob, pamka, ptel, paddr, pemail, firstapp) VALUES
        ("d2e17921-2930-4cbb-bbe5-dc67bcda8482", "evrokas", "evangelos rokas", "1977-07-29 00:00", "29077704913", "6944251692",
        "3, Themistokleous, Str, 18233, Ag. I. Rentis", "evrokas@gmail.com", "2023-11-23 14:00");
INSERT INTO `patients` (guid, cuser, pname, pdob, pamka, ptel, paddr, pemail, firstapp) VALUES
        ("483c6bc1-fb3c-483e-bd33-d721fa7bcce2", "evrokas", "evangelos rokas 2", "1977-07-29 00:00", "29077704913", "6944251692",
        "3, Themistokleous, Str, 18233, Ag. I. Rentis", "evrokas@gmail.com", "2023-11-23 14:00");
INSERT INTO `patients` (guid, cuser, pname, pdob, pamka, ptel, paddr, pemail, firstapp) VALUES
        ("fcad683d-c025-433f-89e6-2126c1967f79", "evrokas", "evangelos rokas 3", "1977-07-29 00:00", "29077704913", "6944251692",
        "3, Themistokleous, Str, 18233, Ag. I. Rentis", "evrokas@gmail.com", "2023-11-23 14:00");
INSERT INTO `patients` (guid, cuser, pname, pdob, pamka, ptel, paddr, pemail, firstapp) VALUES
        ("2955f3cb-ad44-43e4-a561-2efd1b3e8714", "evrokas", "evangelos rokas 4", "1977-07-29 00:00", "29077704913", "2104802442",
        "3, Themistokleous, Str, 18233, Ag. I. Rentis", "evrokas@gmail.com", "2023-11-23 14:00");

TRUNCATE TABLE `users`;
INSERT INTO users (active,expired,username,password,fullname,email,perms) VALUES 
        (true,false,'evrokas',SHA2('vangelis88',256),'Evangelos Rokas', 'evrokas@gmail.com', 'e762f3f8-08f0-4b3f-bf8b-623d28280a18');
INSERT INTO users (active,expired,username,password,fullname,email,perms) VALUES 
        (true,false,'guest',SHA2('guest',256),'Guest user', 'evrokas@hotmail.com', 'e6056ff3-b593-4366-afd7-d3e207c11e6f');

TRUNCATE TABLE `permissions`;
INSERT INTO permissions (guid, name) VALUES
        ("d3247921-2930-4cbb-bbe5-dc67bcda8482", 'admin');
INSERT INTO permissions (guid, name) VALUES
        ("d3647921-2120-4cbb-bbe5-dc67bcda8482", 'viewonly');

TRUNCATE TABLE `permissions_list`;
INSERT INTO permissions_list (user,perm) VALUES
        ('e762f3f8-08f0-4b3f-bf8b-623d28280a18', "d3247921-2930-4cbb-bbe5-dc67bcda8482");
INSERT INTO permissions_list (user,perm) VALUES
        ("e6056ff3-b593-4366-afd7-d3e207c11e6f", 'd3647921-2120-4cbb-bbe5-dc67bcda8482');

INSERT INTO permissions_list (user,perm) VALUES
        ('e762f3f8-08f0-4b3f-bf8b-623d28280a18', "d3647921-2120-4cbb-bbe5-dc67bcda8482");
        
