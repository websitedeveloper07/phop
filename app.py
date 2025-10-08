import subprocess
from flask import Flask, request, jsonify

# Initialize the Flask application
app = Flask(__name__)

# --- CONFIGURATION ---
# The full path to the PHP script you saved in step 1.
# Use an absolute path to avoid issues.
# Example for Linux/macOS: '/var/www/html/gateway_cli.php'
# Example for Windows: 'C:/xampp/htdocs/gateway_cli.php'
PHP_SCRIPT_PATH = "gateway_cli.php"  # <-- IMPORTANT: Change this!

# This is your API key from the URL.
ALLOWED_API_KEYS = ["rockysoon"]

# This is your Stripe Secret Key.
STRIPE_SECRET_KEY = "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"  # <-- IMPORTANT: Put your REAL Stripe secret key here

# --- API ROUTE ---
@app.route('/gateway=skbased/key=<api_key>', methods=['GET'])
def process_payment(api_key):
    """
    This function handles the incoming API request by executing a PHP script.
    """
    # 1. Authenticate the request
    if api_key not in ALLOWED_API_KEYS:
        return jsonify({"status": "error", "message": "Invalid API Key"}), 401

    # 2. Get the credit card list from the ?cc= query parameter
    cc_list = request.args.get('cc')
    if not cc_list:
        return jsonify({"status": "error", "message": "Missing 'cc' parameter"}), 400

    # 3. Prepare the command to execute
    command = [
        "php",              # The command to run the PHP interpreter
        PHP_SCRIPT_PATH,    # Argv[0]: The path to the script
        STRIPE_SECRET_KEY,  # Argv[1]: The secret key
        cc_list             # Argv[2]: The credit card string
    ]

    # 4. Execute the command
    try:
        # We run the command and capture its output (stdout).
        # `check=True` will raise an exception if PHP returns a non-zero exit code (an error).
        # `text=True` ensures the output is decoded as a string.
        result = subprocess.run(
            command, 
            capture_output=True, 
            text=True, 
            check=True, 
            timeout=30
        )
        
        # The output from the `echo` in your PHP script is in `result.stdout`
        return result.stdout, 200

    except FileNotFoundError:
        # This error happens if the "php" command isn't found in the system's PATH
        return jsonify({"status": "error", "message": "PHP interpreter not found. Make sure PHP is installed and in your system's PATH."}), 500
    
    except subprocess.CalledProcessError as e:
        # This happens if the PHP script itself throws an error (e.g., syntax error, or calls `die()`)
        # The error message from PHP will be in `e.stderr`
        return jsonify({
            "status": "error", 
            "message": "The backend PHP script failed to execute.",
            "details": e.stderr
        }), 500
        
    except subprocess.TimeoutExpired:
        return jsonify({"status": "error", "message": "The backend script took too long to respond."}), 504

# To run the app
if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=True)
