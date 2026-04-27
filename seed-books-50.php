<?php
// seed-books-50.php
// Script to populate the database with 50+ realistic books across diverse genres
// Run: php seed-books-50.php

require_once __DIR__ . '/includes/db.php';
$pdo = get_db();

$booksToSeed = [
    // ── Classic Literature ──
    [
        'title' => 'The Great Gatsby',
        'author' => 'F. Scott Fitzgerald',
        'isbn' => '9780743273565',
        'category' => 'Classic Literature',
        'description' => 'The story of the mysteriously wealthy Jay Gatsby and his love for the beautiful Daisy Buchanan.',
        'copies' => 5
    ],
    [
        'title' => '1984',
        'author' => 'George Orwell',
        'isbn' => '9780451524935',
        'category' => 'Classic Literature',
        'description' => 'Among the seminal texts of the 20th century, Nineteen Eighty-Four is a rare work that grows more haunting as its futuristic purgatory becomes more real.',
        'copies' => 3
    ],
    [
        'title' => 'To Kill a Mockingbird',
        'author' => 'Harper Lee',
        'isbn' => '9780060935467',
        'category' => 'Classic Literature',
        'description' => 'The unforgettable novel of a childhood in a sleepy Southern town and the crisis of conscience that rocked it.',
        'copies' => 4
    ],
    [
        'title' => 'Pride and Prejudice',
        'author' => 'Jane Austen',
        'isbn' => '9780141439518',
        'category' => 'Classic Literature',
        'description' => 'A story of love, marriage, and social class in Georgian England, following the spirited Elizabeth Bennet.',
        'copies' => 4
    ],

    // ── Science Fiction ──
    [
        'title' => 'Dune',
        'author' => 'Frank Herbert',
        'isbn' => '9780441172719',
        'category' => 'Science Fiction',
        'description' => 'Set on the desert planet Arrakis, Dune is the story of the boy Paul Atreides, heir to a noble family tasked with ruling an inhospitable world where the only thing of value is the "spice" melange.',
        'copies' => 6
    ],
    [
        'title' => 'The Martian',
        'author' => 'Andy Weir',
        'isbn' => '9780553418026',
        'category' => 'Science Fiction',
        'description' => 'Six days ago, astronaut Mark Watney became one of the first people to walk on Mars. Now he\'s sure he\'ll be the first person to die there.',
        'copies' => 4
    ],
    [
        'title' => 'Foundation',
        'author' => 'Isaac Asimov',
        'isbn' => '9780553293357',
        'category' => 'Science Fiction',
        'description' => 'For twelve thousand years the Galactic Empire has ruled supreme. Now it is dying. But only Hari Seldon has foreseen the dark age to come.',
        'copies' => 3
    ],
    [
        'title' => 'Neuromancer',
        'author' => 'William Gibson',
        'isbn' => '9780441569595',
        'category' => 'Science Fiction',
        'description' => 'The Matrix is a world within the world, a global consensus hallucination, the representation of every byte of data in cyberspace.',
        'copies' => 3
    ],

    // ── Fantasy ──
    [
        'title' => 'The Hobbit',
        'author' => 'J.R.R. Tolkien',
        'isbn' => '9780345339683',
        'category' => 'Fantasy',
        'description' => 'A great modern classic and the prelude to The Lord of the Rings.',
        'copies' => 5
    ],
    [
        'title' => 'A Game of Thrones',
        'author' => 'George R.R. Martin',
        'isbn' => '9780553593716',
        'category' => 'Fantasy',
        'description' => 'Summers span decades. Winter can last a lifetime. And the struggle for the Iron Throne has begun.',
        'copies' => 4
    ],
    [
        'title' => 'Harry Potter and the Sorcerer\'s Stone',
        'author' => 'J.K. Rowling',
        'isbn' => '9780439708180',
        'category' => 'Fantasy',
        'description' => 'Harry Potter has never even heard of Hogwarts when the letters start dropping on the doormat.',
        'copies' => 7
    ],
    [
        'title' => 'The Name of the Wind',
        'author' => 'Patrick Rothfuss',
        'isbn' => '9780756404741',
        'category' => 'Fantasy',
        'description' => 'Told in Kvothe\'s own voice, this is the tale of the magically gifted young man who grows to be the most notorious wizard his world has ever seen.',
        'copies' => 3
    ],

    // ── Mystery / Thriller ──
    [
        'title' => 'The Girl with the Dragon Tattoo',
        'author' => 'Stieg Larsson',
        'isbn' => '9780307454546',
        'category' => 'Mystery',
        'description' => 'Harriet Vanger, a scion of one of Sweden\'s wealthiest families, disappeared over forty years ago. Now her aged uncle wants to know what happened to her.',
        'copies' => 4
    ],
    [
        'title' => 'Gone Girl',
        'author' => 'Gillian Flynn',
        'isbn' => '9780307588371',
        'category' => 'Mystery',
        'description' => 'On a warm summer morning in North Carthage, Missouri, it is Nick and Amy Dunne\'s fifth wedding anniversary.',
        'copies' => 5
    ],
    [
        'title' => 'The Da Vinci Code',
        'author' => 'Dan Brown',
        'isbn' => '9780307474278',
        'category' => 'Mystery',
        'description' => 'While in Paris on business, Harvard symbologist Robert Langdon receives an urgent late-night phone call.',
        'copies' => 6
    ],
    [
        'title' => 'The Silent Patient',
        'author' => 'Alex Michaelides',
        'isbn' => '9781250301697',
        'category' => 'Mystery',
        'description' => 'Alicia Berenson\'s life is seemingly perfect until one evening she shoots her husband five times in the face.',
        'copies' => 4
    ],
    [
        'title' => 'The Girl on the Train',
        'author' => 'Paula Hawkins',
        'isbn' => '9781594634024',
        'category' => 'Mystery',
        'description' => 'Rachel takes the same commuter train every morning and night. She knows it will wait at the same signal each time, and she\'ll observe the same couple having breakfast.',
        'copies' => 3
    ],

    // ── Romance ──
    [
        'title' => 'The Notebook',
        'author' => 'Nicholas Sparks',
        'isbn' => '9781455582877',
        'category' => 'Romance',
        'description' => 'Every so often a love story so captures our hearts that it becomes more than a story—it becomes an experience to remember forever.',
        'copies' => 4
    ],
    [
        'title' => 'Outlander',
        'author' => 'Diana Gabaldon',
        'isbn' => '9780440212560',
        'category' => 'Romance',
        'description' => 'The year is 1945. Claire Randall, a former combat nurse, is just back from the war and reunited with her husband on a second honeymoon.',
        'copies' => 3
    ],
    [
        'title' => 'Me Before You',
        'author' => 'Jojo Moyes',
        'isbn' => '9780143124542',
        'category' => 'Romance',
        'description' => 'Lou Clark knows lots of things. She knows how many footsteps there are between the bus stop and home. She knows she likes working in The Buttered Bun tea shop.',
        'copies' => 3
    ],

    // ── Horror ──
    [
        'title' => 'The Shining',
        'author' => 'Stephen King',
        'isbn' => '9780307743657',
        'category' => 'Horror',
        'description' => 'Jack Torrance\'s new job at the Overlook Hotel is the perfect chance for a fresh start. As the off-season caretaker at the atmospheric old hotel, he\'ll have plenty of time to spend reconnecting with his family.',
        'copies' => 4
    ],
    [
        'title' => 'Dracula',
        'author' => 'Bram Stoker',
        'isbn' => '9780486411095',
        'category' => 'Horror',
        'description' => 'The classic vampire novel that defined the genre, telling the story of Count Dracula\'s attempt to move from Transylvania to England.',
        'copies' => 3
    ],
    [
        'title' => 'Frankenstein',
        'author' => 'Mary Shelley',
        'isbn' => '9780486282114',
        'category' => 'Horror',
        'description' => 'A terrifying vision of scientific progress without moral limits, Mary Shelley\'s Frankenstein leads the reader on an unsettling journey.',
        'copies' => 3
    ],

    // ── Biography / Memoir ──
    [
        'title' => 'Becoming',
        'author' => 'Michelle Obama',
        'isbn' => '9781524763138',
        'category' => 'Biography',
        'description' => 'In her memoir, former First Lady Michelle Obama chronicles the experiences that have shaped her, from childhood to the White House and beyond.',
        'copies' => 5
    ],
    [
        'title' => 'Steve Jobs',
        'author' => 'Walter Isaacson',
        'isbn' => '9781451648539',
        'category' => 'Biography',
        'description' => 'Based on more than forty interviews with Jobs conducted over two years, as well as interviews with more than a hundred family members, friends, and colleagues.',
        'copies' => 3
    ],
    [
        'title' => 'Educated',
        'author' => 'Tara Westover',
        'isbn' => '9780399590504',
        'category' => 'Biography',
        'description' => 'An unforgettable memoir about a young girl who, kept out of school, leaves her survivalist family and goes on to earn a PhD from Cambridge University.',
        'copies' => 4
    ],

    // ── History ──
    [
        'title' => 'Sapiens: A Brief History of Humankind',
        'author' => 'Yuval Noah Harari',
        'isbn' => '9780062316097',
        'category' => 'History',
        'description' => 'From a renowned historian comes a groundbreaking narrative of humanity\'s creation and evolution.',
        'copies' => 3
    ],
    [
        'title' => 'A People\'s History of the United States',
        'author' => 'Howard Zinn',
        'isbn' => '9780062397348',
        'category' => 'History',
        'description' => 'Known for its lively, clear prose as well as its scholarly research, A People\'s History is the only volume to tell America\'s story from the point of view of its women, factory workers, African Americans, Native Americans, and poor.',
        'copies' => 2
    ],
    [
        'title' => 'Guns, Germs, and Steel',
        'author' => 'Jared Diamond',
        'isbn' => '9780393317558',
        'category' => 'History',
        'description' => 'Why did Eurasians conquer, displace, or decimate Native Americans, Australians, and Africans, instead of the reverse?',
        'copies' => 3
    ],

    // ── Self-Help ──
    [
        'title' => 'Atomic Habits',
        'author' => 'James Clear',
        'isbn' => '9780735211292',
        'category' => 'Self-Help',
        'description' => 'No matter your goals, Atomic Habits offers a proven framework for improving—every day.',
        'copies' => 7
    ],
    [
        'title' => 'The 7 Habits of Highly Effective People',
        'author' => 'Stephen R. Covey',
        'isbn' => '9781982137274',
        'category' => 'Self-Help',
        'description' => 'One of the most inspiring and impactful books ever written, this is a principle-centered approach for solving personal and professional problems.',
        'copies' => 4
    ],
    [
        'title' => 'How to Win Friends and Influence People',
        'author' => 'Dale Carnegie',
        'isbn' => '9780671027032',
        'category' => 'Self-Help',
        'description' => 'The only book you need to lead you to success—a time-tested classic that has transformed millions of lives.',
        'copies' => 5
    ],

    // ── Psychology ──
    [
        'title' => 'Thinking, Fast and Slow',
        'author' => 'Daniel Kahneman',
        'isbn' => '9780374533557',
        'category' => 'Psychology',
        'description' => 'The phenomenal New York Times Bestseller by Nobel Prize-winner Daniel Kahneman.',
        'copies' => 5
    ],
    [
        'title' => 'Man\'s Search for Meaning',
        'author' => 'Viktor E. Frankl',
        'isbn' => '9780807014295',
        'category' => 'Psychology',
        'description' => 'Psychiatrist Viktor Frankl\'s memoir has riveted generations of readers with its descriptions of life in Nazi death camps and its lessons for spiritual survival.',
        'copies' => 4
    ],

    // ── Philosophy ──
    [
        'title' => 'Meditations',
        'author' => 'Marcus Aurelius',
        'isbn' => '9780140449334',
        'category' => 'Philosophy',
        'description' => 'Written in Greek by the only Roman emperor who was also a philosopher, without any intention of publication, the Meditations of Marcus Aurelius offer a remarkable series of challenging spiritual reflections.',
        'copies' => 3
    ],
    [
        'title' => 'Thus Spoke Zarathustra',
        'author' => 'Friedrich Nietzsche',
        'isbn' => '9780140441185',
        'category' => 'Philosophy',
        'description' => 'Nietzsche\'s masterpiece, a brilliant and provocative work that dramatically presents the core of his philosophy.',
        'copies' => 2
    ],
    [
        'title' => 'The Republic',
        'author' => 'Plato',
        'isbn' => '9780140455113',
        'category' => 'Philosophy',
        'description' => 'The Republic is Plato\'s masterwork. It is the first great work of political philosophy, and the most enduringly influential classic in the Western canon.',
        'copies' => 2
    ],

    // ── Business / Economics ──
    [
        'title' => 'Rich Dad Poor Dad',
        'author' => 'Robert T. Kiyosaki',
        'isbn' => '9781612680194',
        'category' => 'Business',
        'description' => 'What the rich teach their kids about money that the poor and middle class do not.',
        'copies' => 6
    ],
    [
        'title' => 'The Lean Startup',
        'author' => 'Eric Ries',
        'isbn' => '9780307887894',
        'category' => 'Business',
        'description' => 'Most startups fail. But many of those failures are preventable. The Lean Startup is a new approach being adopted across the globe.',
        'copies' => 4
    ],
    [
        'title' => 'Freakonomics',
        'author' => 'Steven D. Levitt',
        'isbn' => '9780060731335',
        'category' => 'Business',
        'description' => 'Which is more dangerous, a gun or a swimming pool? What do schoolteachers and sumo wrestlers have in common?',
        'copies' => 3
    ],

    // ── Young Adult ──
    [
        'title' => 'The Hunger Games',
        'author' => 'Suzanne Collins',
        'isbn' => '9780439023481',
        'category' => 'Young Adult',
        'description' => 'In the ruins of a place once known as North America lies the nation of Panem, a shining Capitol surrounded by twelve outlying districts.',
        'copies' => 5
    ],
    [
        'title' => 'The Fault in Our Stars',
        'author' => 'John Green',
        'isbn' => '9780525478812',
        'category' => 'Young Adult',
        'description' => 'Despite the tumor-shrinking medical miracle that has bought her a few years, Hazel has never been anything but terminal.',
        'copies' => 4
    ],
    [
        'title' => 'The Book Thief',
        'author' => 'Markus Zusak',
        'isbn' => '9780375842207',
        'category' => 'Young Adult',
        'description' => 'It is 1939. Nazi Germany. The country is holding its breath. Death has never been busier, and will become busier still.',
        'copies' => 3
    ],

    // ── Poetry ──
    [
        'title' => 'The Sun and Her Flowers',
        'author' => 'Rupi Kaur',
        'isbn' => '9781449486792',
        'category' => 'Poetry',
        'description' => 'A vibrant and transcendent journey about growth and healing. Ancestry and honoring one\'s roots. Expatriation and rising up to find a home within yourself.',
        'copies' => 4
    ],
    [
        'title' => 'Milk and Honey',
        'author' => 'Rupi Kaur',
        'isbn' => '9781449474256',
        'category' => 'Poetry',
        'description' => 'Milk and Honey is a collection of poetry and prose about survival. About the experience of violence, abuse, love, loss, and femininity.',
        'copies' => 4
    ],

    // ── Historical Fiction ──
    [
        'title' => 'All the Light We Cannot See',
        'author' => 'Anthony Doerr',
        'isbn' => '9781501173219',
        'category' => 'Historical Fiction',
        'description' => 'Marie-Laure lives in Paris near the Museum of Natural History, where her father works. When she is twelve, the Nazis occupy Paris and father and daughter flee to the walled citadel of Saint-Malo.',
        'copies' => 4
    ],
    [
        'title' => 'The Pillars of the Earth',
        'author' => 'Ken Follett',
        'isbn' => '9780451166890',
        'category' => 'Historical Fiction',
        'description' => 'A masterpiece of medieval historical fiction, set in the 12th century during the turbulent times of civil war and the building of a magnificent cathedral.',
        'copies' => 3
    ],

    // ── True Crime ──
    [
        'title' => 'In Cold Blood',
        'author' => 'Truman Capote',
        'isbn' => '9780679745587',
        'category' => 'True Crime',
        'description' => 'On November 15, 1959, in the small town of Holcomb, Kansas, four members of the Clutter family were savagely murdered by blasts from a shotgun held a few inches from their faces.',
        'copies' => 3
    ],
    [
        'title' => 'The Stranger Beside Me',
        'author' => 'Ann Rule',
        'isbn' => '9780451164933',
        'category' => 'True Crime',
        'description' => 'A chilling account of the author\'s friendship with Ted Bundy, one of the most notorious serial killers in American history.',
        'copies' => 2
    ],

    // ── Technology / Computer Science ──
    [
        'title' => 'The Pragmatic Programmer',
        'author' => 'Andrew Hunt',
        'isbn' => '9780135957059',
        'category' => 'Technology',
        'description' => 'Straight from the programming trenches, The Pragmatic Programmer cuts through the increasing specialization and technicalities of modern software development.',
        'copies' => 3
    ],
    [
        'title' => 'Clean Code',
        'author' => 'Robert C. Martin',
        'isbn' => '9780132350884',
        'category' => 'Technology',
        'description' => 'Even bad code can function. But if code isn\'t clean, it can bring a development organization to its knees.',
        'copies' => 3
    ],

    // ── Health & Fitness ──
    [
        'title' => 'The Power of Habit',
        'author' => 'Charles Duhigg',
        'isbn' => '9780812981605',
        'category' => 'Health & Fitness',
        'description' => 'In The Power of Habit, award-winning business reporter Charles Duhigg takes us to the thrilling edge of scientific discoveries that explain why habits exist and how they can be changed.',
        'copies' => 4
    ],
    [
        'title' => 'Why We Sleep',
        'author' => 'Matthew Walker',
        'isbn' => '9781501144325',
        'category' => 'Health & Fitness',
        'description' => 'Sleep is one of the most important but least understood aspects of our life, wellness, and longevity.',
        'copies' => 3
    ],

    // ── Religion / Spirituality ──
    [
        'title' => 'The Alchemist',
        'author' => 'Paulo Coelho',
        'isbn' => '9780062315007',
        'category' => 'Religion & Spirituality',
        'description' => 'Paulo Coelho\'s enchanting novel has inspired a devoted following around the world. This story, dazzling in its powerful simplicity and soul-stirring wisdom, is about an Andalusian shepherd boy named Santiago.',
        'copies' => 5
    ],
    [
        'title' => 'The Power of Now',
        'author' => 'Eckhart Tolle',
        'isbn' => '9781577314806',
        'category' => 'Religion & Spirituality',
        'description' => 'To make the journey into the Now we will need to leave our analytical mind and its false created self, the ego, behind.',
        'copies' => 3
    ],
];

echo "Starting 50-book database seed...\n";

// Prepare statements
$stmtBook = $pdo->prepare('
    INSERT INTO Books (title, author, isbn, category, description, total_copies, available_copies)
    VALUES (:title, :author, :isbn, :category, :description, :total_copies, :available_copies)
    ON DUPLICATE KEY UPDATE 
        title = VALUES(title), 
        author = VALUES(author),
        category = VALUES(category),
        description = VALUES(description)
');

$stmtCover = $pdo->prepare('
    INSERT INTO book_covers (book_id, image_data, mime_type)
    VALUES (:book_id, :image_data, :mime_type)
    ON DUPLICATE KEY UPDATE 
        image_data = VALUES(image_data), 
        mime_type = VALUES(mime_type)
');

$stmtGetBook = $pdo->prepare('SELECT id FROM Books WHERE isbn = :isbn');

$count = 0;
foreach ($booksToSeed as $b) {
    $count++;
    echo "[$count/50] Processing '{$b['title']}'...\n";

    // Insert Book
    $stmtBook->execute([
        ':title' => $b['title'],
        ':author' => $b['author'],
        ':isbn' => $b['isbn'],
        ':category' => $b['category'],
        ':description' => $b['description'],
        ':total_copies' => $b['copies'],
        ':available_copies' => $b['copies']
    ]);

    // Get Book ID
    $stmtGetBook->execute([':isbn' => $b['isbn']]);
    $bookId = $stmtGetBook->fetchColumn();

    if (!$bookId) {
        echo "  [!] Failed to get book ID for {$b['isbn']}\n";
        continue;
    }

    // Fetch Cover Image from Open Library
    $coverUrl = "https://covers.openlibrary.org/b/isbn/{$b['isbn']}-L.jpg?default=false";
    echo "  Fetching cover from: $coverUrl\n";

    $ch = curl_init($coverUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);

    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($httpCode === 200 && $imageData && strlen($imageData) > 1000) {
        $stmtCover->execute([
            ':book_id' => $bookId,
            ':image_data' => $imageData,
            ':mime_type' => $contentType ?: 'image/jpeg'
        ]);
        echo "  [+] Cover saved successfully.\n";
    } else {
        echo "  [-] Cover not found or invalid (HTTP $httpCode).\n";
    }

    // Small delay to be polite to the API
    usleep(500000);
}

echo "\n✓ Database seed completed. {$count} books processed.\n";
