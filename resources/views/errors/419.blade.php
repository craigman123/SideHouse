<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>CSRF Token Expired</title>
    <link rel="icon" href="{{ asset('images/419.png') }}">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            background-color: #0d1117;
            font-family: Arial, sans-serif;
        }

        h1 {
            color: #c9d1d9;
            text-align: center;
        }

        h2 {
            margin-bottom: 20px;
        }

        .container {
            width: 100%;
            max-width: 600px;
            padding: 20px;
            background-color: #161b22;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            color: #c9d1d9;
            text-align: center;
            border: 2px solid #3f3f3f;
            position: relative;
            overflow: hidden;
        }

        .container::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background-color: #2d9e00;
        }

        .container::after {
            content: "";
            position: absolute;
            top: -40px;
            left: -40px;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(52, 82, 24, 0.15) 0%, rgba(32, 81, 15, 0) 70%);
            pointer-events: none;
        }

        p{
            color: #808080;
        }

        img{
            width: 100%;
            max-width: 180px;
            height: 180px;
            display: block;
            margin: 0 auto 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .text-danger {
            color: #00a003;
            font-weight: bold;
            font-size: 100px;
        }

        .btn-back {
            background: none;
            border: 2px solid #1a511a;
            color: #01c801;
            margin: 20px;
            border-radius: 10px;
            font-weight: bold;
            padding: 13px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><strong class="text-danger">419</strong></h1> <br>
        <h2>CSRF Token Expired</h2>

        <img src="{{ asset('videos/419.gif') }}">

        <p>"This page has expired because too much time passed since it was loaded, or you may have used the browser's back
             button to resubmit an old form. As a security measure, we require a fresh page load before processing your request. 
             Simply refresh the page and try again."</p>
        <button onclick="history.back()" class="btn-back">Go Back</button>
    </div>
</body>
</html>