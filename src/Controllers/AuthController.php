<?php

namespace PhpRss\Controllers;

use PhpRss\Auth;
use PhpRss\View;

/**
 * Controller for handling authentication-related actions.
 * 
 * Manages user login, registration, logout, and the corresponding view pages.
 */
class AuthController
{
    /**
     * Display the login page.
     * 
     * Redirects to dashboard if user is already authenticated.
     * 
     * @return void
     */
    public function loginPage(): void
    {
        if (Auth::check()) {
            header('Location: /dashboard');
            exit;
        }
        View::render('login');
    }

    /**
     * Handle user login form submission.
     * 
     * Validates username and password, attempts authentication, and redirects
     * to dashboard on success or displays error on failure.
     * 
     * @return void
     */
    public function login(): void
    {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            View::render('login', ['error' => 'Please enter both username and password']);
            return;
        }

        if (Auth::login($username, $password)) {
            header('Location: /dashboard');
            exit;
        }

        View::render('login', ['error' => 'Invalid username or password']);
    }

    /**
     * Display the registration page.
     * 
     * Redirects to dashboard if user is already authenticated.
     * 
     * @return void
     */
    public function registerPage(): void
    {
        if (Auth::check()) {
            header('Location: /dashboard');
            exit;
        }
        View::render('register');
    }

    /**
     * Handle user registration form submission.
     * 
     * Validates form data (username, email, password, confirmation), checks
     * password length, and creates a new user account. Redirects to login
     * page on success or displays error on failure.
     * 
     * @return void
     */
    public function register(): void
    {
        $username = $_POST['username'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($username) || empty($email) || empty($password)) {
            View::render('register', ['error' => 'All fields are required']);
            return;
        }

        if ($password !== $confirmPassword) {
            View::render('register', ['error' => 'Passwords do not match']);
            return;
        }

        if (strlen($password) < 6) {
            View::render('register', ['error' => 'Password must be at least 6 characters']);
            return;
        }

        if (Auth::register($username, $email, $password)) {
            View::render('login', ['success' => 'Registration successful. Please login.']);
            return;
        }

        View::render('register', ['error' => 'Username or email already exists']);
    }

    /**
     * Handle user logout.
     * 
     * Destroys the session and redirects to the login page.
     * 
     * @return void
     */
    public function logout(): void
    {
        Auth::logout();
        header('Location: /');
        exit;
    }
}
