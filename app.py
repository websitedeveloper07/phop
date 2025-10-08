import os
import subprocess
from flask import Flask, request, jsonify, Response
import shlex

# ------------------------------
# Initialize the Flask app
# ------------------------------
app = Flask(__name__)

# --- CONFIGURATION ---
PHP_SCRIPT_PATH = "gateway_cli.php"       # Path to your PHP script
ALLOWED_API_KEYS = ["rockysoon"]          # Allowed API keys for authentication


# ------------------------------
# API ROUTE
# ------------------------------
@app.route('/gateway=skbased/key=<api_key>', methods=['GET'])
def process_payment(api_key):
    # 1️⃣ Authenticate the request
    if api_key not in ALLOWED_API_KEYS:
        return jsonify({"status": "error", "message": "Invalid API Key"}), 401

    # 2️⃣ Get the Stripe SK key from query parameter (mandatory)
    sk_key = request.args.get("sk_key", "").strip()
    if not sk_key:
        return jsonify({"status": "error", "message": "Missing 'sk_key' parameter"}), 400

    # 3️⃣ Get the credit card list from the ?cc= query parameter
    cc_list = request.args.get("cc", "").strip()
    if not cc_list:
        return jsonify({"status": "error", "message": "Missing 'cc' parameter"}), 400

    # 4️⃣ Build the PHP command safely
    cmd = [
        "php",
        PHP_SCRIPT_PATH,
        shlex.quote(sk_key),
        shlex.quote(cc_list)
    ]

    # 5️⃣ Execute the PHP script
    try:
        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            check=True,
            timeout=30  # seconds
        )

        # Return the PHP output as HTML
        return Response(result.stdout, mimetype="text/html")

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

    except Exception as e:
        return jsonify({"status": "error", "message": str(e)}), 500


# ------------------------------
# Run the Flask app
# ------------------------------
if __name__ == "__main__":
    port = int(os.environ.get("PORT", 5000))
    app.run(host="0.0.0.0", port=port, debug=True)
