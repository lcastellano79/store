<?php

session_cache_limiter(false);
session_start();
$_SESSION['session_id'] = session_id();

require_once '../vendor/autoload.php';

DB::$dbName = 'hw2estore';
DB::$user = 'hw2estore';
DB::$password = '4rzvhz7htaht9yvd';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// create a log channel
$log = new Logger('main');
$log->pushHandler(new StreamHandler('../logs/everything.log', Logger::DEBUG));
$log->pushHandler(new StreamHandler('../logs/errors.log', Logger::ERROR));

DB::$error_handler = 'sql_error_handler';
DB::$nonsql_error_handler = 'nonsql_error_handler';

function nonsql_error_handler($params) {
    global $app, $log;
    $log->error("Database error: " . $params['error']);
    http_response_code(500);
    $app->render('error_internal.html.twig');
    die;
}

function sql_error_handler($params) {
    global $app, $log;
    $log->error("SQL error: " . $params['error']);
    $log->error(" in query: " . $params['query']);
    http_response_code(500);
    $app->render('error_internal.html.twig');
    die; // don't want to keep going if a query broke
}

$app = new \Slim\Slim(array(
    'view' => new \Slim\Views\Twig()
        ));

$view = $app->view();
$view->parserOptions = array(
    'debug' => true,
    'cache' => dirname(__FILE__) . '/../cache'
);
$view->setTemplatesDirectory(dirname(__FILE__) . '/../templates');

$twig = $app->view->getEnvironment();
$categoryList = DB::query("SELECT * FROM category ORDER BY name");
$twig->addGlobal('categoryList', $categoryList);
$twig->addGlobal('session', $_SESSION);

$app->get('/', function() use ($app) {
    // Selects all products mapped to apear in the homepage
    $productList = DB::query("SELECT * FROM product WHERE isFrontPage != 0 ORDER BY isFrontPage");
    
    // Presents all products
    $app->render('index.html.twig', array( 
        'productList' => $productList
    ));
});

$app->get('/category/:id', function ($id) use ($app) {
    // Presents all product from the specific category
    $productList = DB::query("SELECT * FROM product WHERE categoryID=%i", $id);
    $categoryName = DB::queryFirstField("SELECT name FROM category WHERE id=%i", $id);
    
    // Presents all products
    $app->render('category.html.twig', array(
        'productList' => $productList,
        'categoryName' => $categoryName
    ));
});

$app->get('/product/:id', function ($id) use ($app) {
    // Presents a specific product
    $product = DB::queryFirstRow("SELECT * FROM product WHERE id=%i", $id);
    
    $app->render('product.html.twig', array(
        'product' => $product
    ));
});


$app->get('/cart(/add/:id)', function($id = 0) use ($app) {
    // Adding product to the cart
    if ($id !== 0) {
        // Check if the product has already been added to the cart
        $product = DB::queryFirstRow("SELECT quantity FROM cart_item WHERE sessionID=%s and productID = %i", $_SESSION['session_id'], $id);
        // If not, adds the product
        if (!$product) {
            DB::insert('cart_item', array(
                'id' => NULL,
                'sessionID' => $_SESSION['session_id'],
                'productID' => $id,
                'quantity' => 1,
                'whenCreated' => DB::sqleval("NOW()")
            ));
        // If yes, just updates the quantity
        }else {
            $product['quantity']++;
            DB::query("UPDATE cart_item SET quantity=%i WHERE productID=%i and sessionID=%s", $product['quantity'], $id, $_SESSION['session_id']);
        }
    }
    // Deletes all products with quantity equals to zero
    DB::query("DELETE FROM cart_item WHERE quantity=0 and sessionID=%s", $_SESSION['session_id']);
    // Presents all the itens in the cart
    $cart = DB::query("SELECT product.product_name, cart_item.productID, product.price, cart_item.quantity FROM product, cart_item WHERE sessionID=%s and cart_item.productID = product.id", $_SESSION['session_id']);
    
    $app->render('cart.html.twig', array(
        'cart' => $cart
    ));
});

// Updates the quantity when the user changes the arrows or types into the cart
$app->get('/ajax/cart/set/prod/:id/quantity/:quantity', function($id, $quantity){
    DB::$error_handler = FALSE;
    DB::$throw_exception_on_error = TRUE;
    try {
        DB::query("UPDATE cart_item SET quantity=%i WHERE productID=%i and sessionID=%s", $quantity, $id, $_SESSION['session_id']);
        echo "true";
    }catch (MeekroDBException $e) {
        echo "false";
    }
});


// STATE 1: first show
$app->get('/order', function() use ($app) {
    // Calculates totals, and sends summary from items on cart
    $total_wt = 0;
    $cart = DB::query("SELECT product.product_name, cart_item.productID, product.price, cart_item.quantity FROM product, cart_item WHERE sessionID=%s and cart_item.productID = product.id", $_SESSION['session_id']);
    foreach ($cart as $item) {
        $total_wt += ($item['price'] * $item['quantity']);
    }
    $taxes = round(($total_wt *.15), 2);
    $shipping = 19.99;
    $total = round(($total_wt + $taxes + $shipping), 2);
    
    // Presents cart sumary and form to input customer information
    $app->render('order.html.twig', array(
            'cart'=> $cart,
            'total_wt'=>$total_wt,
            'taxes'=>$taxes,
            'shipping'=>$shipping,
            'total'=>$total));
});

$app->post('/order', function() use ($app) {
    // extract variables
    $first_name = $app->request()->post('first_name');
    $last_name = $app->request()->post('last_name');
    $address = $app->request()->post('address');
    $postcode = $app->request()->post('postcode');
    $country = $app->request()->post('country');
    $provinceorstate = $app->request()->post('provinceorstate');
    $email1 = $app->request()->post('email1');
    $email2 = $app->request()->post('email2');
    $phone = $app->request()->post('phone');
    $credit_card_no = $app->request()->post('credit_card_no');
    $credit_card_expiry = $app->request()->post('credit_card_expiry');
    $credit_card_cvv = $app->request()->post('credit_card_cvv');
    $total_before_tax_and_delivery = $app->request()->post('total_before_tax_and_delivery');
    $delivery = $app->request()->post('delivery');
    $taxes = $app->request()->post('taxes');
    $total_final = $app->request()->post('total_final');
    
    $valueList = array('first_name' => $first_name, 'last_name' => $last_name, 'address' => $address, 
        'postcode' => $postcode, 'country' => $country, 'provinceorstate' => $provinceorstate,
        'email1' => $email1, 'email2' => $email2, 'phone' => $phone, 'credit_card_no' => $credit_card_no, 
        'credit_card_expiry' => $credit_card_expiry, 'credit_card_cvv' => $credit_card_cvv,
        'total_before_tax_and_delivery' => $total_before_tax_and_delivery, 'delivery' => $delivery,
        'taxes' => $taxes, 'total_final' => $total_final);
    
    // Validates first name
    $errorList = array();
    if (strlen($first_name) < 2 || strlen($first_name) > 50) {
        $valueList['first_name'] = '';
        array_push($errorList,
                "First name length must be between 2 and 50 characters.");        
    }elseif (!preg_match('/^[a-zA-Z]+$/', $first_name)) {
        $valueList['first_name'] = '';
        array_push($errorList,
                "First name must consist of lower and upper case letters only."); 
    }
    // Validates last name
    if (strlen($last_name) < 2 || strlen($last_name) > 50) {
        $valueList['last_name'] = '';
        array_push($errorList,
                "Last name length must be between 2 and 50 characters");        
    }elseif (!preg_match('/^[a-zA-Z]+$/', $last_name)) {
        $valueList['last_name'] = '';
        array_push($errorList,
                "Last name must consist of lower and upper case letters only."); 
    }
    // Validades address
    if ((strlen($address) < 10) || (strlen($address) > 100)) {
        $valueList['address'] = '';
        array_push($errorList,
                "Address length must be between 10 and 100 characters.");
    }
    // Validates postcode
    if (empty($postcode) || !preg_match('/^[a-zA-Z][0-9][a-zA-Z]( )[0-9][a-zA-Z][0-9]$/',$postcode)) {
        $valueList['postcode'] = '';
        array_push($errorList,
                "Enter a valid Canadian post code, with space.");
    }
    // Validates country
    if (strlen($country) < 2 || strlen($country) > 50) {
        $valueList['country'] = '';
        array_push($errorList,
                "Country length must be between 2 and 50 characters"); 
    }elseif (!preg_match('/^[a-zA-Z ]+$/', $country)) {
        $valueList['country'] = '';
        array_push($errorList,
                "Country must consist of lower and upper case letters and spaces only.");
    }
    // Validates state and province
    if (strlen($provinceorstate) < 2 || strlen($provinceorstate) > 20) {
        $valueList['provinceorstate'] = '';
        array_push($errorList,
                "Province or State length must be between 2 and 20 characters"); 
    }elseif (!preg_match('/^[a-zA-Z ]+$/', $provinceorstate)) {
        $valueList['provinceorstate'] = '';
        array_push($errorList,
                "Province or State must consist of lower and upper case letters and spaces only."); 
    }
    // Validates e-mail
    if ($email1 !== $email2){
        $valueList['email1'] = '';
        $valueList['email2'] = '';
        array_push($errorList, "E-mails do not match.");
    }elseif (empty($email1)){
        $valueList['email1'] = '';
        $valueList['email2'] = '';
        array_push($errorList, "E-mail must be provided.");
    }elseif (filter_var($email1, FILTER_VALIDATE_EMAIL) === FALSE) {
        $valueList['email1'] = '';
        $valueList['email2'] = '';
        array_push($errorList, "E-mail looks invalid.");
    }elseif (strlen($email1) > 250) {
        $valueList['email1'] = '';
        $valueList['email2'] = '';
        array_push($errorList, "E-mail must not be more than 250 characters long.");
    }
    // Validates phone
    if (empty($phone)) {
        $valueList['phone'] = '';
        array_push($errorList, "Phone number must be provided.");
    }elseif ((strlen($phone) !== 10) || !(preg_match('/^[0-9]+$/', $phone))) {
        $valueList['phone'] = '';
        array_push($errorList, "Phone number must have 10 numeric digits.");
    }
    // Validates credit card number - assumption: 16 numeric digits
    if (empty($credit_card_no)) {
        $valueList['credit_card_no'] = '';
        array_push($errorList, "Credit card number must be provided.");
    } elseif ((strlen($credit_card_no) !== 16) || !(preg_match('/^[0-9]+$/', $credit_card_no))) {
        $valueList['credit_card_no'] = '';
        array_push($errorList, "Credit card number must consist of 16 numeric digits.");
    }
    // Validates card expiry date
    if (empty($credit_card_expiry)) {
        $valueList['credit_card_expiry'] = '';
        array_push($errorList, "Credit card expiry date number must be provided.");
    }elseif ((strlen($credit_card_expiry) !== 4) || !(preg_match('/^[0-9]+$/', $credit_card_expiry))) {
        $valueList['credit_card_expiry'] = '';
        array_push($errorList, "Credit card expiry date consist of 4 numeric digits, on YYMM format.");
    }else {
        $year = substr($credit_card_expiry, 0, 2);
        $month = substr($credit_card_expiry, 2, 2);
        $current_year = date('y');
        $current_month = date('m');
        if ($year < $current_year) {
            $valueList['credit_card_expiry'] = '';
            array_push($errorList, "Invalid expiry date");
        } elseif ($month > 12) {
            $valueList['credit_card_expiry'] = '';
            array_push($errorList, "Invalid expiry date");
        } elseif ($year === $current_year) {
            if ($month < $current_month) {
                $valueList['credit_card_expiry'] = '';
                array_push($errorList, "Invalid expiry date");
            }
        }
        $valueList['credit_card_expiry'] = '20' . $year . '-' . $month . '-01';
    }
    // Validates credit card CVV - assumption: 3 numeric digits
    if (empty($credit_card_cvv)) {
        $valueList['credit_card_cvv'] = '';
        array_push($errorList, "Credit card CVV must be provided.");
    } elseif ((strlen($credit_card_cvv) !== 3) || !(preg_match('/^[0-9]+$/', $credit_card_cvv))) {
        $valueList['credit_card_cvv'] = '';
        array_push($errorList, "Credit card CVV must consist of 3 numeric digits.");
    }
    // Selects all products from cart
    $cart = DB::query("SELECT product.product_name, cart_item.productID, product.price, cart_item.quantity FROM product, cart_item WHERE sessionID=%s and cart_item.productID = product.id", $_SESSION['session_id']);
    // Calculates other info
    $total_wt = 0;
    foreach ($cart as $item) {
        $total_wt += ($item['price'] * $item['quantity']);
    }
    $tx = round(($total_wt *.15), 2);
    $shipping = 19.99;
    $total = round(($total_wt + $tx + $shipping), 2);
    if ($errorList) {
        // STATE 2: failed submission
        $app->render('order.html.twig', array(
            'errorList' => $errorList,
            'v' => $valueList,
            'cart'=> $cart,
            'total_wt'=>$total_wt,
            'taxes'=>$tx,
            'shipping'=>$shipping,
            'total'=>$total));
                
    } else {
        // STATE 3: successful submission
        DB::$error_handler = FALSE;
        DB::$throw_exception_on_error = TRUE;
        try {
            // Starts transaction
            DB::startTransaction();
            // Inserts order header
            DB::insert('order_header', array(
                'id' => NULL,
                'first_name' => $valueList['first_name'],
                'last_name' => $valueList['last_name'],
                'address' => $valueList['address'],
                'postcode' => $valueList['postcode'],
                'country' => $valueList['country'],
                'provinceorstate' => $valueList['provinceorstate'],
                'email' => $valueList['email1'],
                'country' => $valueList['country'],
                'phone' => $valueList['phone'],
                'credit_card_no' => $valueList['credit_card_no'],
                'credit_card_expiry' => $valueList['credit_card_expiry'],
                'credit_card_cvv' => $valueList['credit_card_cvv'],
                'total_before_tax_and_delivery' => $total_wt,
                'delivery' => $shipping,
                'taxes' => $tx,
                'total_final' => $total
            ));
            // Retrives order header ID
            $orderHeaderID = DB::insertId();
            // Copies all cart itens
            $cart = DB::query("SELECT category.name, product.product_name, product.description, product.image_path, product.price, cart_item.quantity FROM category, product, cart_item WHERE sessionID=%s and cart_item.productID = product.id and product.categoryID = category.id", $_SESSION['session_id']);
            // Insert itens to order
            foreach ($cart as $item) {
                DB::insert('order_item', array(
                    'id' => NULL,
                    'orderHeaderID' => $orderHeaderID,
                    'category_name' => $item['name'],
                    'name' => $item['product_name'],
                    'description' => $item['description'],
                    'image_path' => $item['image_path'],
                    'unit_price' => $item['price'],
                    'quantity' => $item['quantity']
                ));
            }
            // Deletes itens from cart
            DB::query("DELETE FROM cart_item where sessionID=%s", $_SESSION['session_id']);
            // Commits transaction
            DB::commit();
            $app->render('order_success.html.twig', array(
                'orderHeaderID' => $orderHeaderID
            ));
        }catch (MeekroDBException $e) {
            // Rollback
            DB::rollback();
            sql_error_handler(array(
                'error' => $e->getMessage(),
                'query' => $e->getQuery()
            ));
        }
    }
});

// STATE 1: first show
$app->get('/login', function() use ($app) {
        $app->render('login.html.twig');
});

$app->post('/login', function() use ($app) {
    // extract variables
    $user_name = $app->request()->post('user_name');
    $password = $app->request()->post('password');
    $valueList = array('user_name' => $user_name, 'password' => $password);
    $errorList = array();
    if (($user_name !== 'admin') || ($password !== '4dmin123')) {
        $valueList['user_name'] = '';
        array_push($errorList, "Invalid credentials");
    }
    if ($errorList) {
        // STATE 2: failed submission
        $app->render('login.html.twig', array(
            'errorList' => $errorList));
    }else {
        // STATE 3: successful submission
        $_SESSION['user'] = $user_name;
        $app->render('admin.html.twig');
    }        

});

$app->get('/logout', function() use ($app) {
    if (!isset($_SESSION['user'])) {
        $app->render('forbiden.html.twig');
    }else {
        unset($_SESSION['person']);
        session_unset();
        $app->render('logout.html.twig');
    }
});

$app->get('/admin', function() use ($app) {
    if (!isset($_SESSION['user'])) {
        $app->render('forbiden.html.twig');
    }else {
        $app->render('admin.html.twig');
    }
});

$app->get('/admin/categories/list', function() use ($app) {
    if (!isset($_SESSION['user'])) {
        $app->render('forbiden.html.twig');
    }else {
        $app->render('admin_categories_list.html.twig');
    }
});

$app->get('/admin/products/list', function() use ($app) {
    if (!isset($_SESSION['user'])) {
        $app->render('forbiden.html.twig');
    }else {
        $productList = DB::query("SELECT product.id, category.name, product.product_name, product.description, product.image_path, product.price, product.IsFrontPage FROM category, product WHERE product.categoryID = category.id");
        $app->render('admin_products.html.twig', array(
                'productList' => $productList));
    }
});

// STATE 1: first show
$app->get('/admin/categories/addedit(/:categoryID)', function($categoryID = 0) use ($app) {
    if (!isset($_SESSION['user'])) {
        $app->render('forbiden.html.twig');
    }else {
        if ($categoryID === 0) {
            $app->render('admin_category_add.html.twig');
        }else {
            $message = '';
            $name = DB::queryFirstField("SELECT name FROM category WHERE id=%s", $categoryID);
            $valueList['category_name'] = $name;
            $valueList['category_id'] = $categoryID;
            $products = DB::query("SELECT * FROM product WHERE categoryID=%i", $categoryID);
            $counter = DB::affectedRows();
            if ($counter > 0) {
                $message = 'Be aware that the following products will be part of the new Category, since the current one will no longer exists.';
            }
            $app->render('admin_category_edit.html.twig', array(
                'valueList' => $valueList,
                'products' => $products,
                'message' => $message));
        }
    }
});

$app->post('/admin/categories/addedit(/:categoryID)', function($categoryID = 0) use ($app) {
    if (!isset($_SESSION['user'])) {
        $app->render('forbiden.html.twig');
    }else {
        // extract variable
        $category_name = $app->request()->post('category_name');
        $valueList = array('category_id' => $categoryID, 'category_name' => $category_name);
        $errorList = array();
        $category = DB::queryFirstRow("SELECT category.name FROM category WHERE category.name=%s", $category_name);
        if ($category) {
            $valueList['category_name'] = '';
            array_push($errorList, "This category already exists.");
        } elseif (strlen($category_name) < 1 || strlen($category_name) > 50) {
            $valueList['category_name'] = '';
            array_push($errorList, "Category name length must be between 1 and 50 characters.");
        }
        if ($errorList) {
        // STATE 2: failed submission
            if ($categoryID === 0) {
                $app->render('admin_category_add.html.twig', array(
                'errorList' => $errorList));
            }else {
                $category_name = $app->request()->post('category_name');
                $app->render('admin_category_edit.html.twig', array(
                'errorList' => $errorList,
                    'valueList' => $valueList));
            }
        }else {
            // STATE 3: successful submission
            if ($categoryID === 0) {
                DB::insert('category', array(
                    'id' => NULL,
                    'name' => $category_name));
                $categoryList = DB::query("SELECT * FROM category ORDER BY name");
                $app->render('admin_categories_list.html.twig', array(
                'categoryList' => $categoryList));
            }else {
                DB::query("UPDATE category SET name=%s WHERE id=%i", $category_name, $categoryID);
                $categoryList = DB::query("SELECT * FROM category ORDER BY name");
                $app->render('admin_categories_list.html.twig', array(
                'categoryList' => $categoryList));
            }                
        }
    }
});

// STATE 1: first show
$app->get('/admin/products/addedit(/:productID)', function($productID = 0) use ($app) {
    if (!isset($_SESSION['user'])) {
        $app->render('forbiden.html.twig');
    }else {
        if ($productID === 0) {
            $app->render('admin_product_add.html.twig');
        }else {
            $valueList = DB::queryFirstRow("SELECT product.id, category.name, product.product_name, product.description, product.image_path, product.price, product.IsFrontPage FROM category, product WHERE product.id=%i and product.categoryID=category.id", $productID);
            /*$categoryList = DB::query("SELECT * FROM category ORDER BY name");*/
            $app->render('admin_product_edit.html.twig', array(
                'valueList' => $valueList/*,
                'categoryList' => $categoryList*/));
        }
    }
});
    
 
$app->post('/admin/products/addedit(/:productID)', function($productID = 0) use ($app) {
    if (!isset($_SESSION['user'])) {
        $app->render('forbiden.html.twig');
    }else {
        // extract variables
        $category_name = $app->request()->post('category_name');
        $product_name = $app->request()->post('product_name');
        $description = $app->request()->post('description');
        $price = $app->request()->post('price');
        $is_front_page = $app->request()->post('is_front_page');
        //$imageUpload = $_FILES['imageUpload']; extracted later not tp have problems on edit whiout changing file
        $valueList = array('name' => $category_name, 'product_name' => $product_name,
            'description' => $description, 'price' => $price,
            'IsFrontPage'=> $is_front_page);        
        $errorList = array();
        // New product add
        if ($productID === 0) {
            $imageUpload = $_FILES['imageUpload'];
            // Checks category
            $category = DB::queryFirstRow("SELECT id, name FROM category WHERE name=%s", $category_name);
            if (!$category['name']){
                $valueList['category_name'] = '';
                array_push($errorList, "Invalid category.");
            }elseif (strlen($category_name) < 1 || strlen($category_name) > 50) {
                $valueList['category_name'] = '';
                array_push($errorList, "Category name length must be between 1 and 50 characters.");
            }
            // Checks product name
            $name = DB::query("SELECT product_name FROM product WHERE product_name=%s", $product_name);
            if ($name) {
                $valueList['product_name'] = '';
                array_push($errorList, "Product name already exists.");
            }elseif (strlen($product_name) < 1 || strlen($product_name) > 100) {
                $valueList['product_name'] = '';
                array_push($errorList, "Product name length must be between 10 and 100 characters.");
            }
            // Checks description
            if (strlen($description) < 10 || strlen($description) > 1000) {
                $valueList['description'] = '';
                array_push($errorList, "Description length must be between 10 and 1000 characters.");
            }
            // Check price
            if (!(preg_match('/^[0-9]+$/', $price)) && !(preg_match('/^[0-9]*\.[0-9]{1,2}$/', $price))) {
                $valueList['price'] = '';
                array_push($errorList, "Price must be numeric and must have maximun 2 decimal digits.");
            }elseif ($price <= 0) {
                $valueList['price'] = '';
                array_push($errorList, "Price must be higher than 0.");
            } // Checks IsFrontPage
            if (!(preg_match('/^[0-9]+$/', $is_front_page))) {
                $valueList['IsFrontPage'] = '';
                array_push($errorList, "IsFrontPage must be a positive integer or 0.");
            }elseif ($is_front_page < 0) {
                $valueList['IsFrontPage'] = '';
                array_push($errorList, "IsFrontPage must be a positive integer or 0.");
            }
            // Validates file
            if ($imageUpload['error'] != 0) {
                array_push($errorList, "Error uploading image.");
            }else {
                $info = getimagesize($imageUpload["tmp_name"]);
                if ($info == FALSE) {
                    array_push($errorList, "Error uploading image, it doesn't look like a valid image file");
                }else {
                    switch ($info['mime']) {
                        case 'image/jpeg': $ext = '.jpg'; break;
                        default: $ext = "." . explode("/", $info['mime'])[1];
                    }
                    $file_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $product_name) . time() . $ext;
                }
            }
            if ($errorList) {
                // STATE 2: failed submission
                $app->render('admin_product_add.html.twig', array(
                'valueList' => $valueList,
                'errorList' => $errorList));
            }else {
                // STATE 3: successful submission
                move_uploaded_file( $imageUpload['tmp_name'], 'images/products/' . $file_name);
                $image_path = '/images/products/' . $file_name;
                DB::insert('product', array(
                    'id' => NULL,
                    'categoryID' => $category['id'],
                    'product_name' => $product_name,
                    'description' => $description,
                    'image_path' => $image_path,
                    'price' => $price,
                    'isFrontPage' => $is_front_page
                        ));
                $product = DB::queryFirstRow("SELECT * FROM product WHERE product_name=%s", $product_name);
                $app->render('admin_product_add_success.html.twig', array(
                'product' => $product
                ));
            }
        // Edit existing product        
        }else {
            // Extract option to change file
            $change_picture = $app->request()->post('change_picture');
            $valueList['id'] = $productID;
            $changes = array();
            $product = DB::queryFirstRow("SELECT product.id, category.name, product.product_name, product.description, product.image_path, product.price, product.isFrontPage FROM category, product WHERE product.categoryID = category.id AND product.id = %s", $productID);
            if ($product) {
                // Checks if category names was changed
                if (!($valueList['name'] === $product['name'])) {
                    // Validates new category name
                    $category = DB::queryFirstRow("SELECT id, name FROM category WHERE name=%s", $category_name);
                    if (!$category['name']){
                        $category_name = $product['name'];
                        array_push($errorList, "Invalid category.");
                    }elseif (strlen($category_name) < 1 || strlen($category_name) > 50) {
                        $valueList['name'] = $product['name'];
                        array_push($errorList, "Category name length must be between 1 and 50 characters.");
                    }else {
                    // If changed and valid, inserts new value to array
                        $changes['categoryID'] = DB::queryFirstField("SELECT id FROM category WHERE name=%s", $valueList['name']);
                    }
                }
                // Checks if product name was changed
                if (!($valueList['product_name'] === $product['product_name'])) {
                    // Validates new product name
                    $name = DB::query("SELECT product_name FROM product WHERE product_name=%s", $valueList['product_name']);
                    if (strlen($valueList['product_name']) < 1 || strlen($valueList['product_name']) > 100) {
                        $valueList['product_name'] = $product['product_name'];
                        array_push($errorList, "Product name length must be between 10 and 100 characters.");
                    } else {
                        // If changed and valid, inserts new value to array
                        $changes['product_name'] = $valueList['product_name'];
                    }    
                }
                // Checks if description was changed
                if (!($valueList['description'] === $product['description'])) {
                    // Validates new description
                    if (strlen($description) < 10 || strlen($description) > 1000) {
                        $valueList['description'] = $product['description'];
                        array_push($errorList, "Description length must be between 10 and 1000 characters.");
                    }else {
                        // If changed and valid, inserts new value to array
                        $changes['description'] = $valueList['description'];
                    }    
                }
                // Checks if price was changed
                if (!($valueList['price'] === $product['price'])) {
                    // Validates new price
                    if (!(preg_match('/^[0-9]+$/', $price)) && !(preg_match('/^[0-9]*\.[0-9]{1,2}$/', $valueList['price']))) {
                        $valueList['price'] = $product['price'];
                        array_push($errorList, "Price must be numeric and must have maximun 2 decimal digits.");
                    }elseif ($valueList['price'] <= 0) {
                        $valueList['price'] = $product['price'];
                        array_push($errorList, "Price must be higher than 0.");
                    } else {
                        // If changed and valid, inserts new value to array
                        $changes['price'] = $valueList['price'];
                    }
                }
                // Checks if isFrontPage was changed
                if (!($valueList['IsFrontPage'] === $product['isFrontPage'])) {
                    // Validates new isFrontPage
                    if (!(preg_match('/^[0-9]+$/', $valueList['IsFrontPage']))) {
                        $valueList['IsFrontPage'] = $product['isFrontPage'];
                        array_push($errorList, "IsFrontPage must be a positive integer or 0.");
                    }elseif ($is_front_page < 0) {
                        $valueList['IsFrontPage'] = $product['isFrontPage'];
                        array_push($errorList, "IsFrontPage must be a positive integer or 0.");
                    }else {
                        // If changed and valid, inserts new value to array
                        $changes['IsFrontPage'] = $valueList['IsFrontPage'];
                    }
                }
                // Check intention to change file
                if ($change_picture === 'yes') { 
                    $imageUpload = $_FILES['imageUpload'];
                    // Check if there was an error
                    if ($imageUpload['error'] != 0) {
                        array_push($errorList, "Error uploading image.");
                    }else {
                        // Validates new File
                        $info = getimagesize($imageUpload["tmp_name"]);
                        if ($info == FALSE) {
                            array_push($errorList, "Error uploading image, it doesn't look like a valid image file");
                        }else {
                            switch ($info['mime']) {
                                case 'image/jpeg': $ext = '.jpg'; break;
                                default: $ext = "." . explode("/", $info['mime'])[1];
                            }
                            // New file name, if product name changed, adapts file name to new product name
                            if (array_key_exists('product_name', $changes)) {
                                $file_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $changes['product_name']) . time() . $ext;
                            }else {
                                // If product name did not change, file mane has the current product name pattern
                                $file_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $product['product_name']) . time() . $ext;
                            }
                            $changes['image_path'] = '/images/products/'. $file_name;           
                        }   
                    }
                } elseif (array_key_exists('product_name', $changes)) {
                    // New file name, if product name changed, adapts file name to new product name
                    $info = getimagesize(__DIR__ . $product['image_path']);
                    switch ($info['mime']) {
                        case 'image/jpeg': $ext = '.jpg'; break;
                        default: $ext = "." . explode("/", $info['mime'])[1];
                    }
                    $file_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $changes['product_name']) . time() . $ext;
                    $image_path = '/images/products/'. $file_name;
                    $changes['image_path'] = $image_path;
                }
                if ($errorList) {
                    // STATE 2: failed submission
                    $app->render('admin_product_edit.html.twig', array(
                    'valueList' => $valueList,
                    'errorList' => $errorList));
                }else {
                    // STATE 3: successful submission
                    if($changes) {
                        // Checks if file was changed
                        if (array_key_exists('image_path', $changes) && !(array_key_exists('product_name', $changes))){
                            move_uploaded_file( $imageUpload['tmp_name'], __DIR__ . $changes['image_path']);
                        } elseif (array_key_exists('image_path', $changes) && (array_key_exists('product_name', $changes)) && ($change_picture === 'yes')) {
                            move_uploaded_file( $imageUpload['tmp_name'], __DIR__ . $changes['image_path']);
                        }elseif (array_key_exists('image_path', $changes) && (array_key_exists('product_name', $changes))) {
                            $result = rename(__DIR__ . $product['image_path'], __DIR__ . $changes['image_path']);
                        }
                        DB::update('product',$changes,"id=%i", $productID);
                        $message = 'Product: ' . $productID . 'successfully updated';
                        $app->render('admin_success.html.twig', array(
                        'message' => $message));
                    }
                }        
            }
        }    
    }
});

$app->get('/admin/products/delete/:productID', function($productID) use ($app) {
    if (!isset($_SESSION['user'])) {
        $app->render('forbiden.html.twig');
    }else {
        $carts = DB::query("SELECT id from cart_item WHERE productID=%i", $productID);
        $counter = DB::affectedRows();
        $message = '';
        if ($counter > 0) { 
            $message = 'There is/are ' . $counter . ' cart(s) with this item. if you chose to proceed, this item will be deleted from all carts.';
        }
        $product = DB::queryFirstRow("SELECT product.id, category.name, product.product_name, product.description, product.image_path, product.price, product.IsFrontPage FROM category, product WHERE product.id=%i and product.categoryID=category.id", $productID);
        $app->render('admin_product_delete.html.twig', array(
                'product' => $product,
                'message' => $message));
    }
});

$app->post('/admin/products/delete/:productID', function($productID) use ($app) {
    if (!isset($_SESSION['user'])) {
        $app->render('forbiden.html.twig');
    }else {
        DB::query("DELETE from cart_item WHERE productID=%i", $productID);
        $file = DB::queryFirstRow("SELECT image_path FROM product WHERE id =%i", $productID);
        $file['image_path'] = __DIR__ . $file['image_path'];
        $errorList = array();
        if (!unlink($file['image_path']))
        {
            array_push($errorList, "Error deleting $file.");
            echo ("Error deleting $file");
        }
        else
        {
            DB::query("DELETE FROM product where id=%i", $productID); 
            $message = 'Product id:' . $productID . ' deleted successfully';
            $app->render('admin_success.html.twig', array(
            'message' => $message));
        }
    }
});

$app->get('/admin/categories/delete/:categoryID', function($categoryID) use ($app) {
    if (!isset($_SESSION['user'])) {
        $app->render('forbiden.html.twig');
    }else {
        $category = DB::queryFirstRow("SELECT name FROM category WHERE id=%i", $categoryID);
        $products = DB::query("SELECT * from product WHERE categoryID=%i", $categoryID);
        $counter = DB::affectedRows();
        if ($counter > 0) { 
            $message = 'Category ' . $category['name'] . ' cannot be deleted. Please delete or edit the following products to be able to delete this Category.';
        }else {
            $message = "Select 'Delete Category' to delete category:" . $category['name'];
        }
        $app->render('admin_category_delete.html.twig', array(
            'category' => $category,
            'products' => $products,
            'message' => $message));
    }
});

$app->post('/admin/categories/delete/:categoryID', function($categoryID) use ($app) {
    if (!isset($_SESSION['user'])) {
        $app->render('forbiden.html.twig');
    }else {
        $products = DB::query("SELECT * from product WHERE categoryID=%i", $categoryID);
        $counter = DB::affectedRows();
        if ($counter > 0) {
            $category = DB::queryFirstRow("SELECT name FROM category WHERE id=%i", $categoryID);
            $message = 'Category ' . $category . 'cannot be deleted.<br>Please delete or edit the following products to be able to delete this Category.';
            $app->render('admin_category_delete.html.twig', array(
            'category' => $category,
            'products' => $products,
            'message' => $message));
        }else {
            DB::query("DELETE FROM category where id=%i", $categoryID); 
            $message = 'Category id:' . $categoryID . ' deleted successfully';
            $app->render('admin_categories_list.html.twig');
        }
    }
});

$app->run();
