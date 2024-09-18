<?php
// Include any necessary PHP logic or configuration here, such as API keys

// Paystack public key
$publicKey = "pk_test_7829d6343c948d7222d71fd1e0ea069309d6cd01"; // Replace 'pk_test_xxxx' with your actual Paystack public key
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paystack Payment</title>
    <script src="https://js.paystack.co/v2/inline.js"></script>
</head>
<body>
    <h2>Pay with Paystack</h2>
    <form id="paymentForm">
        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email-address" required />
        </div>
        <div class="form-group">
            <label for="amount">Amount</label>
            <input type="tel" id="amount" required />
        </div>
        <div class="form-group">
            <button type="submit" onclick="payWithPaystack()">Pay Now</button>
        </div>
    </form>

    <script>
        function payWithPaystack() {

          var transactionRef = 'byr0bogcfzcfxew'; // Replace this with the actual transaction reference

            const popup = new PaystackPop();
            popup.resumeTransaction(transactionRef)
                .then(function(response) {
                    console.log(response); // Handle response
                    alert('Transaction was completed successfully. Reference: ' + response.reference);
                })
                .catch(function(error) {
                    console.error(error); // Handle errors
                    alert('Transaction failed or was cancelled.');
                });
        }
    </script>
</body>
</html>