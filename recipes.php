<?php
require 'config.php';
require_role(['Supervisor', 'Administrator']);


$message = $_SESSION['recipe_message'] ?? '';
unset($_SESSION['recipe_message']);
$error = '';
$product_id = (int) ($_GET['product_id'] ?? $_POST['product_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $product_id = (int) ($_POST['product_id'] ?? 0);
    try {
        if ($product_id < 1) {
            throw new Exception('Select a menu item.');
        }

        if ($action === 'save_component') {
            $ingredientId = (int) ($_POST['ingredient_id'] ?? 0);
            $quantityRequired = $_POST['quantity_required'] ?? '';
            if ($ingredientId < 1 || !is_numeric($quantityRequired) || (float) $quantityRequired <= 0) {
                throw new Exception('Select an ingredient and enter a per-serving quantity greater than zero.');
            }

            $pdo->beginTransaction();
            $productStatement = $pdo->prepare('SELECT name FROM products WHERE id=? AND active=1 FOR UPDATE');
            $productStatement->execute([$product_id]);
            $product = $productStatement->fetch();
            if (!$product) {
                throw new Exception('Menu item not found.');
            }
            $ingredientStatement = $pdo->prepare('SELECT name,unit FROM ingredients WHERE id=? AND active=1 FOR UPDATE');
            $ingredientStatement->execute([$ingredientId]);
            $ingredient = $ingredientStatement->fetch();
            if (!$ingredient) {
                throw new Exception('Ingredient not found.');
            }
            $oldStatement = $pdo->prepare('SELECT quantity_required FROM product_recipe_items WHERE product_id=? AND ingredient_id=? FOR UPDATE');
            $oldStatement->execute([$product_id, $ingredientId]);
            $oldQuantity = $oldStatement->fetchColumn();
            $statement = $pdo->prepare('INSERT INTO product_recipe_items(product_id,ingredient_id,quantity_required) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity_required=VALUES(quantity_required)');
            $statement->execute([$product_id, $ingredientId, $quantityRequired]);
            $change = $oldQuantity === false
                ? 'added ' . number_format((float) $quantityRequired, 3, '.', '') . ' ' . $ingredient['unit'] . ' per serving'
                : 'changed from ' . number_format((float) $oldQuantity, 3, '.', '') . ' to ' . number_format((float) $quantityRequired, 3, '.', '') . ' ' . $ingredient['unit'] . ' per serving';
            audit('Update Menu Recipe', 'product_recipe_items', $product_id, 'Menu item=' . $product['name'] . '; ingredient=' . $ingredient['name'] . '; ' . $change . '.');
            $pdo->commit();
            $_SESSION['recipe_message'] = 'Recipe component saved.';
            header('Location: recipes.php?product_id=' . $product_id);
            exit;
        }

        if ($action === 'remove_component') {
            $ingredientId = (int) ($_POST['ingredient_id'] ?? 0);
            $pdo->beginTransaction();
            $productStatement = $pdo->prepare('SELECT name FROM products WHERE id=? AND active=1 FOR UPDATE');
            $productStatement->execute([$product_id]);
            $product = $productStatement->fetch();
            $ingredientStatement = $pdo->prepare('SELECT name,unit FROM ingredients WHERE id=? FOR UPDATE');
            $ingredientStatement->execute([$ingredientId]);
            $ingredient = $ingredientStatement->fetch();
            $quantityStatement = $pdo->prepare('SELECT quantity_required FROM product_recipe_items WHERE product_id=? AND ingredient_id=? FOR UPDATE');
            $quantityStatement->execute([$product_id, $ingredientId]);
            $quantityRequired = $quantityStatement->fetchColumn();
            if (!$product || !$ingredient || $quantityRequired === false) {
                throw new Exception('Recipe component not found.');
            }
            $statement = $pdo->prepare('DELETE FROM product_recipe_items WHERE product_id=? AND ingredient_id=?');
            $statement->execute([$product_id, $ingredientId]);
            audit('Remove Recipe Ingredient', 'product_recipe_items', $product_id, 'Menu item=' . $product['name'] . '; removed ' . number_format((float) $quantityRequired, 3, '.', '') . ' ' . $ingredient['unit'] . ' per serving.');
            $pdo->commit();
            $_SESSION['recipe_message'] = 'Recipe component removed.';
            header('Location: recipes.php?product_id=' . $product_id);
            exit;
        }

        throw new Exception('Unknown recipe action.');
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'The recipe change could not be saved.';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}

$products = $pdo->query('SELECT id,name,category FROM products WHERE active=1 ORDER BY name')->fetchAll();
if ($product_id < 1 && $products) {
    $product_id = (int) $products[0]['id'];
}
$selectedProduct = null;
foreach ($products as $product) {
    if ((int) $product['id'] === $product_id) {
        $selectedProduct = $product;
        break;
    }
}
$ingredients = $pdo->query('SELECT id,name,unit,quantity FROM ingredients WHERE active=1 ORDER BY name')->fetchAll();
$recipeItems = [];
if ($selectedProduct) {
    $statement = $pdo->prepare('SELECT r.ingredient_id,r.quantity_required,i.name,i.unit,i.quantity FROM product_recipe_items r JOIN ingredients i ON i.id=r.ingredient_id WHERE r.product_id=? ORDER BY i.name');
    $statement->execute([$product_id]);
    $recipeItems = $statement->fetchAll();
}
include 'partials/header.php';
?>
<div class="page-head">
    <div>
        <h2>Menu Recipes</h2>
        <p class="muted">Set ingredient quantities required for one menu serving.</p>
    </div>
    <a class="btn secondary" href="ingredients.php">Ingredient Stock</a>
</div>
<?php if ($message): ?>
    <div class="alert success"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert danger"><?= e($error) ?></div>
<?php endif; ?>
<section class="card">
    <form method="get" class="filter">
        <label>Menu item
            <select name="product_id" required>
                <?php foreach ($products as $product): ?>
                    <option value="<?= (int) $product['id'] ?>" <?= (int) $product['id'] === $product_id ? 'selected' : '' ?>><?= e($product['name']) ?> (<?= e($product['category']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn secondary" type="submit">Load Recipe</button>
    </form>
</section>
<?php if ($selectedProduct): ?>
    <div class="grid-two">
        <section class="card">
            <h3>Add or Update Ingredient</h3>
            <?php if ($ingredients): ?>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="save_component">
                    <input type="hidden" name="product_id" value="<?= $product_id ?>">
                    <label>Ingredient
                        <select name="ingredient_id" required>
                            <option value="">Select ingredient</option>
                            <?php foreach ($ingredients as $ingredient): ?>
                                <option value="<?= (int) $ingredient['id'] ?>"><?= e($ingredient['name']) ?> (<?= e($ingredient['unit']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Quantity required per serving
                        <input type="number" name="quantity_required" min="0.001" step="0.001" required placeholder="0.250">
                    </label>
                    <button class="btn primary" type="submit">Save Recipe Component</button>
                </form>
            <?php else: ?>
                <p class="muted">Add ingredients and stock before building recipes.</p>
                <a class="btn secondary" href="ingredients.php">Add Ingredients</a>
            <?php endif; ?>
        </section>
        <section class="card">
            <h3><?= e($selectedProduct['name']) ?> Recipe</h3>
            <?php if ($recipeItems): ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Ingredient</th><th>Per serving</th><th>On hand</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($recipeItems as $item): ?>
                                <tr>
                                    <td><?= e($item['name']) ?></td>
                                    <td><?= e(number_format((float) $item['quantity_required'], 3, '.', '')) ?> <?= e($item['unit']) ?></td>
                                    <td><?= e(number_format((float) $item['quantity'], 3, '.', '')) ?> <?= e($item['unit']) ?></td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('Remove this ingredient from the recipe?');">
                                            <input type="hidden" name="action" value="remove_component">
                                            <input type="hidden" name="product_id" value="<?= $product_id ?>">
                                            <input type="hidden" name="ingredient_id" value="<?= (int) $item['ingredient_id'] ?>">
                                            <button class="btn danger-btn" type="submit">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="muted">No recipe is configured. This item continues using its existing direct stock until ingredients are added here.</p>
            <?php endif; ?>
        </section>
    </div>
<?php else: ?>
    <section class="card"><p class="muted">Create a menu item before assigning a recipe.</p></section>
<?php endif; ?>
<?php include 'partials/footer.php'; ?>
