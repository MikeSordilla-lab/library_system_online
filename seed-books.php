<?php
// seed-books.php
// Script to populate the database with realistic books and cover images

require_once __DIR__ . '/includes/db.php';
$pdo = get_db();

$booksToSeed = [
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
        'title' => 'Dune',
        'author' => 'Frank Herbert',
        'isbn' => '9780441172719',
        'category' => 'Science Fiction',
        'description' => 'Set on the desert planet Arrakis, Dune is the story of the boy Paul Atreides, heir to a noble family tasked with ruling an inhospitable world where the only thing of value is the "spice" melange.',
        'copies' => 6
    ],
    [
        'title' => 'The Hobbit',
        'author' => 'J.R.R. Tolkien',
        'isbn' => '9780345339683',
        'category' => 'Fantasy',
        'description' => 'A great modern classic and the prelude to The Lord of the Rings.',
        'copies' => 5
    ],
    [
        'title' => 'Atomic Habits',
        'author' => 'James Clear',
        'isbn' => '9780735211292',
        'category' => 'Self-Help',
        'description' => 'No matter your goals, Atomic Habits offers a proven framework for improving--every day.',
        'copies' => 7
    ],
    [
        'title' => 'Sapiens: A Brief History of Humankind',
        'author' => 'Yuval Noah Harari',
        'isbn' => '9780062316097',
        'category' => 'History',
        'description' => 'From a renowned historian comes a groundbreaking narrative of humanity’s creation and evolution.',
        'copies' => 3
    ],
    [
        'title' => 'The Catcher in the Rye',
        'author' => 'Harper Lee',
        'isbn' => '9780316769488',
        'category' => 'Classic Literature',
        'description' => 'The hero-narrator of The Catcher in the Rye is an ancient child of sixteen, a native New Yorker named Holden Caulfield.',
        'copies' => 2
    ],
    [
        'title' => 'The Martian',
        'author' => 'Andy Weir',
        'isbn' => '9780553418026',
        'category' => 'Science Fiction',
        'description' => 'Six days ago, astronaut Mark Watney became one of the first people to walk on Mars. Now, he\'s sure he\'ll be the first person to die there.',
        'copies' => 4
    ],
    [
        'title' => 'Thinking, Fast and Slow',
        'author' => 'Daniel Kahneman',
        'isbn' => '9780374533557',
        'category' => 'Psychology',
        'description' => 'The phenomenal New York Times Bestseller by Nobel Prize-winner Daniel Kahneman.',
        'copies' => 5
    ]
];

echo "Starting database seed...\n";

// Disable foreign key checks for clean sweep (optional, maybe we just add to it, but let's clear existing dummy ones)
// $pdo->exec('SET FOREIGN_KEY_CHECKS = 0; TRUNCATE TABLE book_covers; TRUNCATE TABLE Books; SET FOREIGN_KEY_CHECKS = 1;');
// Actually, let's not truncate, just insert if ISBN doesn't exist

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

foreach ($booksToSeed as $b) {
    echo "Processing '{$b['title']}'...\n";

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
    // Don't download if it redirects to the 1x1 pixel 404 image (though default=false helps)
    curl_setopt($ch, CURLOPT_FAILONERROR, true);

    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($httpCode === 200 && $imageData && strlen($imageData) > 1000) {
        // Assume valid image if larger than 1KB
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

echo "Database seed completed.\n";
