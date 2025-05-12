<?php
/*
Plugin Name: Selectable Boxes Plugin
Description: A simple plugin to create selectable boxes with a button that appears only on the active box.
Version: 1.0
Author: Carlos Murillo
Author URI: https://lucumaagency.com/
License: GPL-2.0+
*/

function selectable_boxes_shortcode() {
    ob_start();
    ?>
    <div class="box-container">
        <div class="box selected" onclick="selectBox(this, 'box1')">
            <h3>Buy This Course</h3>
            <p class="price">$749.99 USD</p>
            <p class="description">Pay once, Have the course forever</p>
            <button>Buy Course</button>
        </div>
        <div class="box no-button" onclick="selectBox(this, 'box2')">
            <h3>Enroll in the Live Course</h3>
            <p class="price">$1249.99 USD</p>
            <p class="description">Take the live course over 6 weeks</p>
            <button>Buy Course</button>
        </div>
    </div>

    <style>
        .box-container {
            display: block;
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .box {
            position: relative;
            width: 100%;
            max-width: 350px;
            padding: 15px;
            background: transparent;
            border: 2px solid #9B9FAA7A;
            border-radius: 15px;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 20px;
            box-sizing: border-box;
        }
        .box.selected {
            background: linear-gradient(180deg, rgba(242, 46, 190, 0.2) 0%, rgba(170, 0, 212, 0.2) 100%);
            border: none;
        }
        .box:not(.selected) {
            opacity: 0.7;
        }
        .box.no-button button {
            display: none;
        }
        .box h3 {
            color: #fff !important;
            margin: 5px 0 10px;
            font-size: 1.5em;
        }
        .box .price {
            font-size: 1.2em;
            font-weight: bold;
        }
        .box .description {
            font-size: 0.9em;
            margin: 10px 0;
        }
        .box button {
            width: 100%;
            padding: 10px;
            background-color: #ff3e8e;
            border: none;
            border-radius: 25px;
            color: white;
            font-size: 1em;
            cursor: pointer;
        }
        .box:not(.selected) button {
            background-color: #cc3071;
        }

        /* Media Queries para Responsive */
        @media (min-width: 768px) {
            .box-container {
                display: flex;
                justify-content: center;
                flex-wrap: wrap;
                gap: 20px;
            }
            .box {
                width: 100%;
                max-width: 350px;
                margin-bottom: 0;
            }
        }
        @media (max-width: 767px) {
            .box {
                padding: 10px;
            }
            .box h3 {
                font-size: 1.2em;
            }
            .box .price {
                font-size: 1em;
            }
            .box .description {
                font-size: 0.8em;
            }
            .box button {
                padding: 8px;
                font-size: 0.9em;
            }
        }
    </style>

    <script>
        function selectBox(element, boxId) {
            document.querySelectorAll('.box').forEach(box => {
                box.classList.remove('selected');
                box.classList.add('no-button');
            });
            element.classList.remove('no-button');
            element.classList.add('selected');
            document.getElementById('selected-box')?.setValue(boxId);
        }
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('selectable_boxes', 'selectable_boxes_shortcode');
?>
