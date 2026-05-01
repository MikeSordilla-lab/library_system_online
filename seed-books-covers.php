<?php
// seed-books-covers.php
// Script to populate the database with 120 books using local cover images
// Run: php seed-books-covers.php

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/src/config/constants.php';

$pdo = get_db();

$coverDir = __DIR__ . '/assets/images/covers/book cover';
$coverFiles = glob($coverDir . '/*.jpg');
sort($coverFiles);

$booksToSeed = [
    ['title' => 'The Great Gatsby', 'author' => 'F. Scott Fitzgerald', 'isbn' => '9780743273565', 'category' => 'Classic Literature', 'description' => 'The story of the mysteriously wealthy Jay Gatsby and his love for the beautiful Daisy Buchanan.', 'copies' => 5],
    ['title' => '1984', 'author' => 'George Orwell', 'isbn' => '9780451524935', 'category' => 'Classic Literature', 'description' => 'Among the seminal texts of the 20th century, Nineteen Eighty-Four is a rare work that grows more haunting as its futuristic purgatory becomes more real.', 'copies' => 3],
    ['title' => 'To Kill a Mockingbird', 'author' => 'Harper Lee', 'isbn' => '9780060935467', 'category' => 'Classic Literature', 'description' => 'The unforgettable novel of a childhood in a sleepy Southern town and the crisis of conscience that rocked it.', 'copies' => 4],
    ['title' => 'Pride and Prejudice', 'author' => 'Jane Austen', 'isbn' => '9780141439518', 'category' => 'Classic Literature', 'description' => 'A story of love, marriage, and social class in Georgian England, following the spirited Elizabeth Bennet.', 'copies' => 4],
    ['title' => 'Dune', 'author' => 'Frank Herbert', 'isbn' => '9780441172719', 'category' => 'Science Fiction', 'description' => 'Set on the desert planet Arrakis, Dune is the story of the boy Paul Atreides, heir to a noble family tasked with ruling an inhospitable world.', 'copies' => 6],
    ['title' => 'The Martian', 'author' => 'Andy Weir', 'isbn' => '9780553418026', 'category' => 'Science Fiction', 'description' => 'Six days ago, astronaut Mark Watney became one of the first people to walk on Mars. Now he\'s sure he\'ll be the first person to die there.', 'copies' => 4],
    ['title' => 'Foundation', 'author' => 'Isaac Asimov', 'isbn' => '9780553293357', 'category' => 'Science Fiction', 'description' => 'For twelve thousand years the Galactic Empire has ruled supreme. Now it is dying. But only Hari Seldon has foreseen the dark age to come.', 'copies' => 3],
    ['title' => 'Neuromancer', 'author' => 'William Gibson', 'isbn' => '9780441569595', 'category' => 'Science Fiction', 'description' => 'The Matrix is a world within the world, a global consensus hallucination, the representation of every byte of data in cyberspace.', 'copies' => 3],
    ['title' => 'The Hobbit', 'author' => 'J.R.R. Tolkien', 'isbn' => '9780345339683', 'category' => 'Fantasy', 'description' => 'A great modern classic and the prelude to The Lord of the Rings.', 'copies' => 5],
    ['title' => 'A Game of Thrones', 'author' => 'George R.R. Martin', 'isbn' => '9780553593716', 'category' => 'Fantasy', 'description' => 'Summers span decades. Winter can last a lifetime. And the struggle for the Iron Throne has begun.', 'copies' => 4],
    ['title' => 'Harry Potter and the Sorcerer\'s Stone', 'author' => 'J.K. Rowling', 'isbn' => '9780439708180', 'category' => 'Fantasy', 'description' => 'Harry Potter has never even heard of Hogwarts when the letters start dropping on the doormat.', 'copies' => 5],
    ['title' => 'The Fellowship of the Ring', 'author' => 'J.R.R. Tolkien', 'isbn' => '9780547928227', 'category' => 'Fantasy', 'description' => 'The first volume of The Lord of the Rings.', 'copies' => 4],
    ['title' => 'The Catcher in the Rye', 'author' => 'J.D. Salinger', 'isbn' => '9780316769488', 'category' => 'Classic Literature', 'description' => 'The story of Holden Caulfield and his teenage angst and alienation.', 'copies' => 4],
    ['title' => 'Brave New World', 'author' => 'Aldous Huxley', 'isbn' => '9780060850524', 'category' => 'Science Fiction', 'description' => 'A dystopian novel set in a futuristic World State of engineered citizens.', 'copies' => 3],
    ['title' => 'The Alchemist', 'author' => 'Paulo Coelho', 'isbn' => '9780062315007', 'category' => 'Fiction', 'description' => 'A magical story about Santiago, an Andalusian shepherd boy who yearns to travel in search of treasure.', 'copies' => 5],
    ['title' => 'Atomic Habits', 'author' => 'James Clear', 'isbn' => '9780735211292', 'category' => 'Self-Help', 'description' => 'An Easy & Proven Way to Build Good Habits & Break Bad Ones.', 'copies' => 4],
    ['title' => 'Sapiens', 'author' => 'Yuval Noah Harari', 'isbn' => '9780062316097', 'category' => 'History', 'description' => 'A Brief History of Humankind.', 'copies' => 4],
    ['title' => 'The Da Vinci Code', 'author' => 'Dan Brown', 'isbn' => '9780307474278', 'category' => 'Mystery', 'description' => 'A mystery thriller novel about a murder in the Louvre Museum.', 'copies' => 5],
    ['title' => 'The Hobbit', 'author' => 'J.R.R. Tolkien', 'isbn' => '9780547928227', 'category' => 'Fantasy', 'description' => 'A fantasy novel about the adventures of Bilbo Baggins.', 'copies' => 4],
    ['title' => 'The Shining', 'author' => 'Stephen King', 'isbn' => '9780307743657', 'category' => 'Horror', 'description' => 'A horror novel about a family in an isolated hotel.', 'copies' => 3],
    ['title' => 'It', 'author' => 'Stephen King', 'isbn' => '9781501142970', 'category' => 'Horror', 'description' => 'A horror novel about a shapeshifting entity that preys on children.', 'copies' => 3],
    ['title' => 'The Outsider', 'author' => 'Stephen King', 'isbn' => '9781501181009', 'category' => 'Horror', 'description' => 'A horror novel about a murder investigation in a small town.', 'copies' => 3],
    ['title' => 'Misery', 'author' => 'Stephen King', 'isbn' => '9781501142970', 'category' => 'Horror', 'description' => 'A thriller about a famous author held captive by a fan.', 'copies' => 3],
    ['title' => 'The Stand', 'author' => 'Stephen King', 'isbn' => '9780307743688', 'category' => 'Horror', 'description' => 'An epic post-apocalyptic horror novel.', 'copies' => 3],
    ['title' => 'Carrie', 'author' => 'Stephen King', 'isbn' => '9781501142970', 'category' => 'Horror', 'description' => 'The story of a bullied teen with telekinetic powers.', 'copies' => 3],
    ['title' => 'The Power of Habit', 'author' => 'Charles Duhigg', 'isbn' => '9780812981605', 'category' => 'Self-Help', 'description' => 'Why We Do What We Do in Life and Business.', 'copies' => 4],
    ['title' => 'Thinking, Fast and Slow', 'author' => 'Daniel Kahneman', 'isbn' => '9780374533557', 'category' => 'Psychology', 'description' => 'A book about the two systems that drive the way we think.', 'copies' => 3],
    ['title' => 'The 7 Habits of Highly Effective People', 'author' => 'Stephen R. Covey', 'isbn' => '9781451639612', 'category' => 'Self-Help', 'description' => 'Powerful Lessons in Personal Change.', 'copies' => 4],
    ['title' => 'How to Win Friends and Influence People', 'author' => 'Dale Carnegie', 'isbn' => '9780671027032', 'category' => 'Self-Help', 'description' => 'The classic guide to relationships and leadership.', 'copies' => 4],
    ['title' => 'Rich Dad Poor Dad', 'author' => 'Robert Kiyosaki', 'isbn' => '9781612680178', 'category' => 'Finance', 'description' => 'What the Rich Teach Their Kids About Money.', 'copies' => 4],
    ['title' => 'The Lean Startup', 'author' => 'Eric Ries', 'isbn' => '9780307887894', 'category' => 'Business', 'description' => 'How Today\'s Entrepreneurs Use Continuous Innovation.', 'copies' => 3],
    ['title' => 'Zero to One', 'author' => 'Peter Thiel', 'isbn' => '9780804139298', 'category' => 'Business', 'description' => 'Notes on Startups, or How to Build the Future.', 'copies' => 3],
    ['title' => 'The Girl with the Dragon Tattoo', 'author' => 'Stieg Larsson', 'isbn' => '9780307454546', 'category' => 'Mystery', 'description' => 'A mystery novel about a journalist and a hacker.', 'copies' => 4],
    ['title' => 'Gone Girl', 'author' => 'Gillian Flynn', 'isbn' => '9780307588371', 'category' => 'Mystery', 'description' => 'A thriller about a wife who goes missing.', 'copies' => 4],
    ['title' => 'The Kite Runner', 'author' => 'Khaled Hosseini', 'isbn' => '9781594631931', 'category' => 'Fiction', 'description' => 'A story of friendship, betrayal, and redemption in Afghanistan.', 'copies' => 4],
    ['title' => 'A Thousand Splendid Suns', 'author' => 'Khaled Hosseini', 'isbn' => '9780746276986', 'category' => 'Fiction', 'description' => 'A story of two women in Afghanistan.', 'copies' => 4],
    ['title' => 'The Notebook', 'author' => 'Nicholas Sparks', 'isbn' => '9781538764701', 'category' => 'Romance', 'description' => 'A love story about a man who reads to a woman with dementia.', 'copies' => 5],
    ['title' => 'The Fault in Our Stars', 'author' => 'John Green', 'isbn' => '9780142424179', 'category' => 'Young Adult', 'description' => 'A story about two teenagers with cancer.', 'copies' => 4],
    ['title' => 'Looking for Alaska', 'author' => 'John Green', 'isbn' => '9780142402511', 'category' => 'Young Adult', 'description' => 'A story about a girl named Alaska Young.', 'copies' => 4],
    ['title' => 'The Perks of Being a Wallflower', 'author' => 'Stephen Chbosky', 'isbn' => '9781451696734', 'category' => 'Young Adult', 'description' => 'A coming-of-age novel told through letters.', 'copies' => 4],
    ['title' => 'Divergent', 'author' => 'Veronica Roth', 'isbn' => '9780062024022', 'category' => 'Young Adult', 'description' => 'A dystopian young adult novel set in a divided society.', 'copies' => 4],
    ['title' => 'The Hunger Games', 'author' => 'Suzanne Collins', 'isbn' => '9780439023481', 'category' => 'Young Adult', 'description' => 'A dystopian novel about a televised death match.', 'copies' => 5],
    ['title' => 'The Maze Runner', 'author' => 'James Dashner', 'isbn' => '9780385737944', 'category' => 'Young Adult', 'description' => 'A group of teens in a mysterious maze.', 'copies' => 4],
    ['title' => 'Twilight', 'author' => 'Stephenie Meyer', 'isbn' => '9780316160179', 'category' => 'Young Adult', 'description' => 'A romance between a human and a vampire.', 'copies' => 5],
    ['title' => 'The Handmaid\'s Tale', 'author' => 'Margaret Atwood', 'isbn' => '9780385490818', 'category' => 'Science Fiction', 'description' => 'A dystopian novel set in a totalitarian society.', 'copies' => 3],
    ['title' => 'The Road', 'author' => 'Cormac McCarthy', 'isbn' => '9780307387899', 'category' => 'Fiction', 'description' => 'A father and son journey through post-apocalyptic America.', 'copies' => 3],
    ['title' => 'Life of Pi', 'author' => 'Yann Martel', 'isbn' => '9780156027328', 'category' => 'Fiction', 'description' => 'A boy survives a shipwreck with a tiger.', 'copies' => 4],
    ['title' => 'The Book Thief', 'author' => 'Markus Zusak', 'isbn' => '9780375842207', 'category' => 'Fiction', 'description' => 'A story about a girl in Nazi Germany who steals books.', 'copies' => 4],
    ['title' => 'The Curious Incident of the Dog in the Night-Time', 'author' => 'Mark Haddon', 'isbn' => '9780385510820', 'category' => 'Mystery', 'description' => 'A murder mystery narrated by an autistic teenager.', 'copies' => 4],
    ['title' => 'The Silence of the Lambs', 'author' => 'Thomas Harris', 'isbn' => '9780061124952', 'category' => 'Thriller', 'description' => 'An FBI trainee seeks help from a cannibalistic serial killer.', 'copies' => 3],
    ['title' => 'Jurassic Park', 'author' => 'Michael Crichton', 'isbn' => '9780345538987', 'category' => 'Science Fiction', 'description' => 'A theme park of cloned dinosaurs turns deadly.', 'copies' => 4],
    ['title' => 'The Andromeda Strain', 'author' => 'Michael Crichton', 'isbn' => '9780060762884', 'category' => 'Science Fiction', 'description' => 'A deadly extraterrestrial microorganism threatens Earth.', 'copies' => 3],
    ['title' => 'Sphere', 'author' => 'Michael Crichton', 'isbn' => '9780060930535', 'category' => 'Science Fiction', 'description' => 'A group discovers a massive sphere in the ocean.', 'copies' => 3],
    ['title' => 'The Time Machine', 'author' => 'H.G. Wells', 'isbn' => '9780141439716', 'category' => 'Science Fiction', 'description' => 'A Victorian scientist travels to the future.', 'copies' => 3],
    ['title' => 'The War of the Worlds', 'author' => 'H.G. Wells', 'isbn' => '9780141439723', 'category' => 'Science Fiction', 'description' => 'Martians invade Earth in this classic novel.', 'copies' => 3],
    ['title' => 'Frankenstein', 'author' => 'Mary Shelley', 'isbn' => '9780141439471', 'category' => 'Classic Literature', 'description' => 'A scientist creates a creature and must face the consequences.', 'copies' => 4],
    ['title' => 'Dracula', 'author' => 'Bram Stoker', 'isbn' => '9780141439846', 'category' => 'Horror', 'description' => 'The classic vampire novel.', 'copies' => 4],
    ['title' => 'The Picture of Dorian Gray', 'author' => 'Oscar Wilde', 'isbn' => '9780141439570', 'category' => 'Classic Literature', 'description' => 'A portrait ages while a man stays forever young.', 'copies' => 3],
    ['title' => 'The Count of Monte Cristo', 'author' => 'Alexandre Dumas', 'isbn' => '9780140449266', 'category' => 'Classic Literature', 'description' => 'An adventure story of revenge and redemption.', 'copies' => 4],
    ['title' => 'Les Misérables', 'author' => 'Victor Hugo', 'isbn' => '9780451419439', 'category' => 'Classic Literature', 'description' => 'A French epic novel about justice and redemption.', 'copies' => 4],
    ['title' => 'Anna Karenina', 'author' => 'Leo Tolstoy', 'isbn' => '9780143035008', 'category' => 'Classic Literature', 'description' => 'A tragic love story in 19th century Russia.', 'copies' => 4],
    ['title' => 'War and Peace', 'author' => 'Leo Tolstoy', 'isbn' => '9780143039990', 'category' => 'Classic Literature', 'description' => 'An epic novel about the Napoleonic Wars in Russia.', 'copies' => 4],
    ['title' => 'Crime and Punishment', 'author' => 'Fyodor Dostoevsky', 'isbn' => '9780143058144', 'category' => 'Classic Literature', 'description' => 'A psychological novel about guilt and redemption.', 'copies' => 3],
    ['title' => 'The Brothers Karamazov', 'author' => 'Fyodor Dostoevsky', 'isbn' => '9780374528379', 'category' => 'Classic Literature', 'description' => 'A philosophical novel about faith and doubt.', 'copies' => 3],
    ['title' => 'Wuthering Heights', 'author' => 'Emily Brontë', 'isbn' => '9780141439556', 'category' => 'Classic Literature', 'description' => 'A tale of passionate and destructive love.', 'copies' => 3],
    ['title' => 'Jane Eyre', 'author' => 'Charlotte Brontë', 'isbn' => '9780141441146', 'category' => 'Classic Literature', 'description' => 'A story of independence and romance.', 'copies' => 4],
    ['title' => 'The Odyssey', 'author' => 'Homer', 'isbn' => '9780140268867', 'category' => 'Classic Literature', 'description' => 'The epic journey of Odysseus home to Ithaca.', 'copies' => 4],
    ['title' => 'The Iliad', 'author' => 'Homer', 'isbn' => '9780140275360', 'category' => 'Classic Literature', 'description' => 'The tale of the Trojan War.', 'copies' => 4],
    ['title' => 'The Divine Comedy', 'author' => 'Dante Alighieri', 'isbn' => '9780140448955', 'category' => 'Classic Literature', 'description' => 'An epic journey through Hell, Purgatory, and Paradise.', 'copies' => 3],
    ['title' => 'Don Quixote', 'author' => 'Miguel de Cervantes', 'isbn' => '9780060934347', 'category' => 'Classic Literature', 'description' => 'The adventures of a man who thinks he\'s a knight.', 'copies' => 4],
    ['title' => 'Moby-Dick', 'author' => 'Herman Melville', 'isbn' => '9780142437247', 'category' => 'Classic Literature', 'description' => 'Captain Ahab\'s obsessive quest for the white whale.', 'copies' => 3],
    ['title' => 'The Odyssey', 'author' => 'Homer', 'isbn' => '9780140268867', 'category' => 'Classic Literature', 'description' => 'The epic journey of Odysseus home to Ithaca.', 'copies' => 4],
    ['title' => 'Gone with the Wind', 'author' => 'Margaret Mitchell', 'isbn' => '9781451635621', 'category' => 'Classic Literature', 'description' => 'The story of the American South during the Civil War.', 'copies' => 4],
    ['title' => 'Rebecca', 'author' => 'Daphne du Maurier', 'isbn' => '9780062073662', 'category' => 'Mystery', 'description' => 'A classic gothic mystery.', 'copies' => 4],
    ['title' => 'The Great Expectations', 'author' => 'Charles Dickens', 'isbn' => '9780141439563', 'category' => 'Classic Literature', 'description' => 'The story of Pip\'s journey to become a gentleman.', 'copies' => 4],
    ['title' => 'A Tale of Two Cities', 'author' => 'Charles Dickens', 'isbn' => '9780141439600', 'category' => 'Classic Literature', 'description' => 'A story of the French Revolution.', 'copies' => 4],
    ['title' => 'Oliver Twist', 'author' => 'Charles Dickens', 'isbn' => '9780141439747', 'category' => 'Classic Literature', 'description' => 'The story of an orphan in Victorian London.', 'copies' => 4],
    ['title' => 'David Copperfield', 'author' => 'Charles Dickens', 'isbn' => '9780140439473', 'category' => 'Classic Literature', 'description' => 'The life story of David Copperfield.', 'copies' => 3],
    ['title' => 'The Adventures of Sherlock Holmes', 'author' => 'Arthur Conan Doyle', 'isbn' => '9780141439471', 'category' => 'Mystery', 'description' => 'Collection of detective stories.', 'copies' => 4],
    ['title' => 'The Hound of the Baskervilles', 'author' => 'Arthur Conan Doyle', 'isbn' => '9780141439570', 'category' => 'Mystery', 'description' => 'Sherlock Holmes investigates a spectral hound.', 'copies' => 4],
    ['title' => 'The Adventures of Tom Sawyer', 'author' => 'Mark Twain', 'isbn' => '9780143039563', 'category' => 'Classic Literature', 'description' => 'The adventures of a boy in antebellum Missouri.', 'copies' => 4],
    ['title' => 'Adventures of Huckleberry Finn', 'author' => 'Mark Twain', 'isbn' => '9780142437162', 'category' => 'Classic Literature', 'description' => 'A boy and a runaway slave travel down the Mississippi.', 'copies' => 4],
    ['title' => 'The Call of the Wild', 'author' => 'Jack London', 'isbn' => '9780142437216', 'category' => 'Classic Literature', 'description' => 'A dog becomes a wild animal in the Yukon.', 'copies' => 3],
    ['title' => 'White Fang', 'author' => 'Jack London', 'isbn' => '9780140186420', 'category' => 'Classic Literature', 'description' => 'The story of a wolf-dog in the Yukon.', 'copies' => 3],
    ['title' => 'The Jungle Book', 'author' => 'Rudyard Kipling', 'isbn' => '9780143036326', 'category' => 'Classic Literature', 'description' => 'Stories about Mowgli and his animal friends.', 'copies' => 4],
    ['title' => 'The Secret Garden', 'author' => 'Frances Hodgson Burnett', 'isbn' => '9780142437230', 'category' => 'Classic Literature', 'description' => 'A girl discovers a hidden garden.', 'copies' => 4],
    ['title' => 'Little Women', 'author' => 'Louisa May Alcott', 'isbn' => '9780147514011', 'category' => 'Classic Literature', 'description' => 'The story of the March sisters.', 'copies' => 4],
    ['title' => 'Anne of Green Gables', 'author' => 'L.M. Montgomery', 'isbn' => '9780142437209', 'category' => 'Classic Literature', 'description' => 'The story of an orphan girl in Prince Edward Island.', 'copies' => 4],
    ['title' => 'Peter Pan', 'author' => 'J.M. Barrie', 'isbn' => '9780142437322', 'category' => 'Classic Literature', 'description' => 'The boy who never grows up.', 'copies' => 4],
    ['title' => 'Winnie-the-Pooh', 'author' => 'A.A. Milne', 'isbn' => '9780142437216', 'category' => 'Classic Literature', 'description' => 'The adventures of Pooh and friends.', 'copies' => 4],
    ['title' => 'The Wind in the Willows', 'author' => 'Kenneth Grahame', 'isbn' => '9780142437384', 'category' => 'Classic Literature', 'description' => 'The adventures of Mole, Rat, Badger, and Toad.', 'copies' => 3],
    ['title' => 'The Secret Agent', 'author' => 'Joseph Conrad', 'isbn' => '9780141441610', 'category' => 'Classic Literature', 'description' => 'A political thriller set in London.', 'copies' => 3],
    ['title' => 'Heart of Darkness', 'author' => 'Joseph Conrad', 'isbn' => '9780141439518', 'category' => 'Classic Literature', 'description' => 'A journey into the Congo.', 'copies' => 4],
    ['title' => 'Lord of the Flies', 'author' => 'William Golding', 'isbn' => '9780399501487', 'category' => 'Classic Literature', 'description' => 'Children stranded on an island turn to savagery.', 'copies' => 4],
    ['title' => 'The Old Man and the Sea', 'author' => 'Ernest Hemingway', 'isbn' => '9780684801223', 'category' => 'Classic Literature', 'description' => 'A fisherman\'s epic battle with a giant marlin.', 'copies' => 4],
    ['title' => 'A Farewell to Arms', 'author' => 'Ernest Hemingway', 'isbn' => '9780684801711', 'category' => 'Classic Literature', 'description' => 'A love story set during World War I.', 'copies' => 3],
    ['title' => 'For Whom the Bell Tolls', 'author' => 'Ernest Hemingway', 'isbn' => '9780684803357', 'category' => 'Classic Literature', 'description' => 'An American fights with Spanish guerrillas.', 'copies' => 3],
    ['title' => 'The Sun Also Rises', 'author' => 'Ernest Hemingway', 'isbn' => '9780743285057', 'category' => 'Classic Literature', 'description' => 'The Lost Generation in post-WWI Paris.', 'copies' => 3],
    ['title' => 'The Grapes of Wrath', 'author' => 'John Steinbeck', 'isbn' => '9780143039433', 'category' => 'Classic Literature', 'description' => 'A family flees the Dust Bowl.', 'copies' => 4],
    ['title' => 'Of Mice and Men', 'author' => 'John Steinbeck', 'isbn' => '9780140186420', 'category' => 'Classic Literature', 'description' => 'Two friends dream of owning a farm.', 'copies' => 4],
    ['title' => 'East of Eden', 'author' => 'John Steinbeck', 'isbn' => '9780140186390', 'category' => 'Classic Literature', 'description' => 'A multigenerational story in California.', 'copies' => 3],
    ['title' => 'The Bell Jar', 'author' => 'Sylvia Plath', 'isbn' => '9780060837563', 'category' => 'Classic Literature', 'description' => 'A young woman\'s descent into mental illness.', 'copies' => 3],
    ['title' => 'One Flew Over the Cuckoo\'s Nest', 'author' => 'Ken Kesey', 'isbn' => '9780451162434', 'category' => 'Classic Literature', 'description' => 'A rebel challenges a mental institution.', 'copies' => 4],
    ['title' => 'Catch-22', 'author' => 'Joseph Heller', 'isbn' => '9781451626650', 'category' => 'Classic Literature', 'description' => 'A satirical novel about WWII.', 'copies' => 3],
    ['title' => 'The Crying of Lot 49', 'author' => 'Thomas Pynchon', 'isbn' => '9780060933319', 'category' => 'Classic Literature', 'description' => 'A woman discovers a mysterious postal system.', 'copies' => 2],
    ['title' => 'Slaughterhouse-Five', 'author' => 'Kurt Vonnegut', 'isbn' => '9780385333481', 'category' => 'Classic Literature', 'description' => 'A soldier becomes unstuck in time.', 'copies' => 3],
    ['title' => 'Cat\'s Cradle', 'author' => 'Kurt Vonnegut', 'isbn' => '9780385333481', 'category' => 'Science Fiction', 'description' => 'A satirical sci-fi novel about the end of the world.', 'copies' => 3],
    ['title' => 'Breakfast of Champions', 'author' => 'Kurt Vonnegut', 'isbn' => '9780385334204', 'category' => 'Classic Literature', 'description' => 'A satirical tale about America.', 'copies' => 2],
    ['title' => 'The Godfather', 'author' => 'Mario Puzo', 'isbn' => '9780451166890', 'category' => 'Fiction', 'description' => 'The story of a crime family.', 'copies' => 4],
    ['title' => 'Jaws', 'author' => 'Peter Benchley', 'isbn' => '9780553382560', 'category' => 'Thriller', 'description' => 'A great white shark terrorizes a beach town.', 'copies' => 4],
    ['title' => 'The Exorcist', 'author' => 'William Peter Blatty', 'isbn' => '9780060803312', 'category' => 'Horror', 'description' => 'A priest battles a demonic entity.', 'copies' => 3],
    ['title' => 'The Amityville Horror', 'author' => 'Jay Anson', 'isbn' => '9781453263547', 'category' => 'Horror', 'description' => 'A family experiences terrifying events in a house.', 'copies' => 3],
];

echo "Found " . count($coverFiles) . " cover images.\n";
echo "Processing " . count($booksToSeed) . " books.\n\n";

$stmtBook = $pdo->prepare('
    INSERT INTO Books (title, author, isbn, category, description, total_copies, available_copies)
    VALUES (:title, :author, :isbn, :category, :description, :total_copies, :available_copies)
');

$stmtCover = $pdo->prepare('
    INSERT INTO book_covers (book_id, image_data, mime_type)
    VALUES (:book_id, :image_data, :mime_type)
    ON DUPLICATE KEY UPDATE image_data = VALUES(image_data), mime_type = VALUES(mime_type)
');

$count = 0;
$coverIndex = 0;
$isbnBase = 2000000000000;

foreach ($booksToSeed as $b) {
    $count++;
    echo "[$count] Inserting '{$b['title']}'... ";

    $uniqueIsbn = (string)($isbnBase + $count);
    $stmtBook->execute([
        ':title' => $b['title'],
        ':author' => $b['author'],
        ':isbn' => $uniqueIsbn,
        ':category' => $b['category'],
        ':description' => $b['description'],
        ':total_copies' => $b['copies'],
        ':available_copies' => $b['copies']
    ]);

    $bookId = $pdo->lastInsertId();
    echo "ID: $bookId ";

    if ($coverIndex < count($coverFiles)) {
        $coverPath = $coverFiles[$coverIndex];
        $imageData = file_get_contents($coverPath);
        
        if ($imageData !== false) {
            $mimeType = 'image/jpeg';
            $stmtCover->execute([
                ':book_id' => $bookId,
                ':image_data' => $imageData,
                ':mime_type' => $mimeType
            ]);
            echo "Cover: " . basename($coverPath) . " ✓";
        } else {
            echo "Cover: FAILED TO READ";
        }
        $coverIndex++;
    } else {
        echo "Cover: No more images";
    }

    echo "\n";
}

echo "\n✓ Database seed completed. $count books processed.\n";