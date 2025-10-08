import os
import subprocess
from flask import Flask, request, jsonify

# Initialize the Flask application
app = Flask(__name__)

# --- CONFIGURATION ---
PHP_SCRIPT_PATH = "gateway_cli.php"  # Hardcoded PHP script path
ALLOWED_API_KEYS = ["rockysoon"]     # Hardcoded API keys
STRIPE_SECRET_KEY = "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"  # Hardcoded Stripe key

# --- API ROUTE ---
@app.route('/gateway=skbased/key=<api_key>', methods=['GET'])
def process_payment(api_key):
    # 1. Authenticate the request
    if api_key not in ALLOWED_API_KEYS:
        return jsonify({"status": "error", "message": "Invalid API Key"}), 401

    # 2. Get the credit card list from the ?cc= query parameter
    cc_list = request.args.get('cc')
    if not cc_list:
        return jsonify({"status": "error", "message": "Missing 'cc' parameter"}), 400

    # 3. Prepare the command to execute
    command = [
        "php",
        PHP_SCRIPT_PATH,
        STRIPE_SECRET_KEY,
        cc_list
    ]

    # 4. Execute the command
    try:
        result = subprocess.run(
            command,
            capture_output=True,
            text=True,
            check=True,
            timeout=30
        )
        return result.stdout, 200

    except FileNotFoundError:
        return jsonify({"status": "error", "message": "PHP interpreter not found."}), 500

    except subprocess.CalledProcessError as e:
        return jsonify({
            "status": "error",
            "message": "The backend PHP script failed to execute.",
            "details": e.stderr
        }), 500

    except subprocess.TimeoutExpired:
        return jsonify({"status": "error", "message": "The backend script took too long to respond."}), 504


# --- Run the app ---
if __name__ == '__main__':
    # Use Render's dynamic PORT if set, fallback to 5000 for local testing
    port = int(os.environ.get("PORT", 5000))
    app.run(host='0.0.0.0', port=port, debug=True)
